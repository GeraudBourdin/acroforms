<?php

/*
The MIT License (MIT)

Copyright (c) 2019 Jacques Archimède

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is furnished
to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

namespace acroforms\Parser;

use acroforms\Filter\FilterFactory;
use acroforms\Model\AcroField;
use acroforms\Model\CrossReference;
use acroforms\Model\PDFDocument;
use acroforms\Utils\StringToolBox;

/**
 *
 * Class to parse the lines of a PDF file.
 *
 */
class PDFParser {
	const METAS = [ "Title", "Author", "Subject", "Keywords", "Creator", "Producer", "CreationDate", "ModDate" ];

	private $pdfDocument = null; 

	private $converter = null;

	private $linesCount;
	private $pointers = [];
	private $objects = []; // name and parent of objects by object id

	private $objectPosition = 0; //Position of an object, in the order it is declared in the pdf file

	public function __construct(PDFDocument $pdfDocument) {
		$this->pdfDocument = $pdfDocument;
		$this->converter = FilterFactory::getFilter("ASCIIHexDecode");
	}

	/**
	 * Decodes a PDF value according to the encoding
	 * 
	 * @param string $encoding  the encoding to use for decoding the value, only 'hex' is supported
	 * @param string $value a value to decode
	 * @return string the value decoded
	 */
	private function decodeValue($encoding, $value) {
		if ($encoding == "hex") {
			$value = $this->converter->decode($value);
		}
		return $value;
	}

	/**
	 * Parses the lines entries of a PDF 
	 */
	public function parse() {
		$this->linesCount = $this->pdfDocument->getEntriesCount();
		for ($i = 0, $p = 0; $i < $this->linesCount; $i++ ) {
			$entry = $this->pdfDocument->getEntry($i);
			$this->pointers[$i] = $p;
			$p += strlen($entry) + 1;
		}
		$this->parseObjects();
		$this->parseCrossReference();
		$this->parseTrailer();
		$this->parseStartxref();
	}

	private function parseObjects() {
		$from = 0;
		while ( $from < $this->linesCount ) {
			$from = $this->parseObject($from);
		}
	}

	private function parseObject($from) {
		$match = [];
		while ($from < $this->linesCount) { 
			$entry = $this->pdfDocument->getEntry($from);
			if (preg_match("/^(\d+) (\d+) obj/", $entry, $match)) {
				break;
			}
			$from++;
		}
		if ($from < $this->linesCount) {
			$defaultMaxLen = 0; //No limit
			$defaultTooltipLine = 0; //Tooltip is optional as it may not be defined
			$parentId = 0;
			$removed = false;
			$objectId = intval($match[1]);
			$this->pdfDocument->setOffset($objectId, $this->pointers[$from]);
			$this->pdfDocument->setPosition($objectId, $this->objectPosition);
			$this->pdfDocument->setShift($this->objectPosition, 0);
			$this->objectPosition++;
			$field = new AcroField(intval($objectId));
			$field->setMaxLen($defaultMaxLen);
			$field->setTooltip($defaultTooltipLine);
			$entry = $this->pdfDocument->getEntry(++$from);
			while ( $from < $this->linesCount && ! preg_match("/endobj/", $entry)) {
				$removed = $removed || ! $this->parseFieldProperty($entry, $from, $field, $parentId);
				$entry = $this->pdfDocument->getEntry(++$from);
			}
			if ($field->getType() != '' // it's a field
				&& $field->getName() != '' // with a name
				&& !$removed // not removed 
				&& !$field->isPushButton()) { // and not a push button
				$name = preg_replace("/\[\d+\]$/", "", $field->getName());
				$this->pdfDocument->setField($name, $field);
			}
		}
		return $from;
	}

	private function parseFieldProperty($entry, $from, &$field, &$parentId) {
		$removed = false;
		$match = [];
		foreach (self::METAS as $meta) {
			if (preg_match("/\/" . $meta . "\s*\(([^\)]+)\)/", $entry, $match)) {
				$this->pdfDocument->addMeta($meta, $match[1]);
			} elseif (preg_match("/\/" . $meta . "\s*\<([^\>]+)\>/", $entry, $match)) {
				$this->pdfDocument->addMeta($meta, $this->decodeValue("hex", $match[1]));
			}
		}
		if (preg_match("/\/Trapped\s*\/(.+)$/",$entry, $match)) {
			$this->pdfDocument->addMeta("Trapped", strtolower($match[1]));
		} elseif (preg_match("/^\/T\s?\((.+)\)\s*$/", StringToolBox::protectParentheses($entry), $match)) {
			$this->parseName($from, $match, $field, $parentId);
		} elseif (preg_match("/^\/(V|DV|TU)\s+([\<\(\/])/", $entry, $match)) {
			$this->parseValue($from, $match, $field);
		} elseif (preg_match("/^\/MaxLen\s+(\d+)/", $entry, $match)) {
			$field->setMaxLen(intval($match[1]));
		} elseif (preg_match("/^\/removed\s+true/", $entry)) {
			$removed = true;
		} elseif (preg_match("/^\/Parent\s+(\d+)/", $entry, $match)) {
			$parentId = $match[1];
		} elseif (preg_match("/^\/FT\s+\/(.+)$/", $entry, $match)) {
			$field->setType($match[1]); // Tx, Btn, Ch or Sig
		} elseif (preg_match("/^\/Ff\s+(\d+)/", $entry, $match)) {
			$field->setFlag(intval($match[1]));
		} elseif (preg_match("/^\/Opt\s+\[(.+)\]\s*$/", $entry, $match)) {
			$this->parseOptions($match, $field);
		} elseif (preg_match("/^\/TI\s+\/(.+)$/", $entry, $match)) {
			$field->setTopIndex($match[1]);
		} elseif (preg_match("/^\/I\s+\[(.+)\]\s*$/", $entry, $match)) {
			$field->setSelecteds(array_map(function ($sel) {
				return intval($sel);
			}, preg_split("/\s+/", trim($match[1]))));
		}
		if (substr($entry, 0, 7) == '/Fields' && !$this->pdfDocument->isNeedAppearancesTrue()) {
			$this->pdfDocument->setEntry($from, '/NeedAppearances true ' . $entry);
		}
		return !$removed;
	}

	private function parseName($from, $match, &$field, &$parentId) {
		$name = StringToolBox::unProtectParentheses($match[1]);
		$field->setName($name);
		if ($field->getFullName() != '') {
			$field->setFullName($field->getFullName() . "." . $name);
		} else {
			$field->setFullName($name);
		}
		$field->setNameLine($from);
		$objectId = $field->getId();
		$this->objects[$objectId] = [ 'name' => $name, 'parent' => $parentId ];
		$fullName = [];
		while (isset($this->objects[$parentId])) {
			array_unshift($fullName, $this->objects[$parentId]['name']);
			$parentId = $this->objects[$parentId]['parent'];
		}
		if (! empty($fullName)) {
			$field->setFullName(implode(".", $fullName) . "." . $name);
		} else {
			$field->setFullName($name);
		}
	}

	private function parseValue($from, $match, &$field) {
		if ($match[1] == "TU") {
			$field->setTooltip($from);
		} elseif ($match[1] == "DV") {
			$field->setDefaultValue($from);
		} else {
			$field->setCurrentValue($from);
		}
	}

	private function parseOptions($match, &$field) {
		$array = $match[1];
		if (preg_match_all("/\[([^\]]+)\]/", $array, $match)) {
			$array = $match[1];
			$options = [];
			foreach ($array as $option) {
				if (preg_match("/^\s*\(([^\)]+)\)\s*\(([^\)]+)\)\s*$/", $option, $match)) {
					$options[$match[1]] = $match[2];
				} elseif (preg_match("/^\s*\(([^\)]+)\)\s*$/", $option, $match)) {
					$options[$match[1]] = $match[1];
				} 
			}
			if (!empty($options)) {
				$field->setOptions($options);
			}
		}
	}

	private function parseCrossReference() {
		$from = $this->linesCount - 1;
		while ($from >= 0) { 
			$entry = $this->pdfDocument->getEntry($from);
			if (preg_match("/\bxref\b/", $entry)) {
				break;
			}
			 $from--;
		}
		if ($from < 0) {
			throw new \Exception("PDFParser: xref tag not found");
		}
		if ($from == $this->linesCount - 1) {
			throw new \Exception(sprintf("PDFParser: PDF document is corruptd, last entry found : %s", $entry));
		}
		$refsCount = 0;
		$xrefTable = 1;
		$crossReference = new CrossReference($this->pdfDocument);
		$crossReference->setLine($from);
		$startPointer = $this->pointers[$from] + strpos($entry, "xref");
		$crossReference->setStartPointer($startPointer);
		$entry = $this->pdfDocument->getEntry(++$from);
		while ( $from < $this->linesCount && ! preg_match("/^trailer/", $entry)) {
			$match = [];
			$xrefTable++;
			switch($xrefTable) {
				case 2:
					if (preg_match("/^(\d+) (\d+)/",$entry, $match)) {
						$refsCount = intval($match[2]);
						$crossReference->setCount($refsCount - 1);
					}
					break;
				case 3:
					break;
				default:
					if ($refsCount > 0) {
						$xref = $xrefTable - 3;
						if ($refsCount == 1) {
							throw new \Exception("PDFParser: xrefTable length corrupted?: Trailer not found at expected!");
						} else {
							$crossReference->setEntry($xref, $entry);
						}
						$refsCount--;
					}
			}
			$entry = $this->pdfDocument->getEntry(++$from);
		}
		$this->pdfDocument->setCrossReference($crossReference);
	}

	private function parseTrailer() {
		$from = $this->linesCount - 1;
		while ($from >= 0) { 
			$entry = $this->pdfDocument->getEntry($from);
			if (preg_match("/^trailer/", $entry)) {
				break;
			}
			 $from--;
		}
		if ($from < 0) {
			throw new \Exception("PDFParser: trailer tag not found");
		}
		if ($from == $this->linesCount - 1) {
			throw new \Exception(sprintf("PDFParser: PDF document is corruptd, last entry found : %s", $entry));
		}
		$inIDDefinition = false;
		$isSingleLineIDDefinition = false;
		$isMultiLineIDDefinition = false;
		$oid = '';
		$entry = $this->pdfDocument->getEntry(++$from);
		while ( $from < $this->linesCount && ! preg_match("/^startxref/", $entry)) {
			$match = [];
			if (!$inIDDefinition || preg_match("/^\/(Size|Root|Info|ID|DocChecksum)/", $entry, $match)) {
				if (preg_match("/\/Size (\d+)/", $entry, $match)) {
					$this->pdfDocument->addMeta("size", $match[1]);
				}
				if (preg_match("/^\/ID\s*\[\s*<([\da-fA-F]+)/", $entry, $match)) {
					$oid = $match[1];
					$inIDDefinition = true;
					if (preg_match("/\>\s?\</", $entry, $match)) {
						$isSingleLineIDDefinition = true;
					}
				}
				if ($inIDDefinition) {
					if ($isSingleLineIDDefinition || $isMultiLineIDDefinition) {
						if (preg_match("/([\da-fA-F]+)>.*$/", $entry, $match)) {
							$this->pdfDocument->addMeta("ID", array($oid, $match[1]));
							$inIDDefinition = false;
						} else {
							throw new \Exception("PDFParser: trailer corrupted?; ID chunk two can not be decoded ");
						}
					} else { 
						$isMultiLineIDDefinition = true;
					}
				}
				if (preg_match("/^\/DocChecksum \/([\da-fA-F]+)/", $entry, $match)) {
					$this->pdfDocument->addMeta("checksum", $match[1]);
				}
			}
			$entry = $this->pdfDocument->getEntry(++$from);
		}
	}

	private function parseStartxref() {
		$match = [];
		$from = $this->linesCount - 1;
		while ($from >= 0) { 
			$entry = $this->pdfDocument->getEntry($from);
			if (preg_match("/^startxref/", $entry)) {
				break;
			}
			 $from--;
		}
		if ($from < 0) {
			throw new \Exception("PDFParser: startxref tag not found");
		}
		if ($from == $this->linesCount - 1) {
			throw new \Exception(sprintf("PDFParser: PDF document is corruptd, last entry found : %s", $entry));
		}
		$entry = $this->pdfDocument->getEntry(++$from);
		if (preg_match("/^(\d+)/", $entry, $match)) {
			$this->pdfDocument->getCrossReference()->setStartValue(intval($match[1]));
			$this->pdfDocument->getCrossReference()->setStartLine($from);
		} else {
			throw new \Exception(sprintf("PDFParser: startxref value expected, found : %s", $entry));
		}
	}

}
