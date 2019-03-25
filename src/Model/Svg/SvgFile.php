<?php
/**
 * This file contains classes for manipulating the contents of an SVG file.
 * Intended to centralise references to PHP's byzantine DOM manipulation system.
 *
 * @file
 */

declare(strict_types = 1);

namespace App\Model\Svg;

use App\Exception\SvgLoadException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXpath;
use Krinkle\Intuition\Intuition;

class SvgFile
{
    /**
     * @var DOMDocument
     */
    private $document;

    /**
     * @var DOMXpath
     */
    private $xpath = null;

    /**
     * @var bool
     */
    private $isTranslationReady = false;

    /**
     * @var array
     */
    private $savedLanguages;

    /**
     * @var array
     */
    private $inFileTranslations;

    /**
     * @var array
     */
    private $filteredTextNodes;

    /**
     * @var string
     */
    private $fallbackLanguage;

    /**
     * Construct an SvgFile object.
     *
     * @param string $path
     * @throws SvgLoadException
     */
    public function __construct(string $path)
    {
        $this->fallbackLanguage = 'fallback';

        $this->document = new DOMDocument('1.0');

        // Warnings need to be suppressed in case there are DOM warnings
        if (!$this->document->load($path, LIBXML_NOWARNING)) {
            throw new SvgLoadException();
        }
        $this->xpath = new DOMXpath($this->document);

        $this->xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');

        // $this->isTranslationReady() can be used to test if construction was a success
        $this->makeTranslationReady();
    }

    /**
     * Was the file successfully made translation ready i.e. is it translatable?
     *
     * @return bool
     */
    public function isTranslationReady(): bool
    {
        return $this->isTranslationReady;
    }

    /**
     * Makes $this->document ready for translation by inserting <switch> tags where they need to be, etc.
     * Also works as a check on the compatibility of the file since it will return false if it fails.
     *
     * @todo Find a way of making isTranslationReady a proper check
     * @todo add interlanguage consistency check
     * @return bool False on failure, DOMDocument on success
     */
    protected function makeTranslationReady(): bool
    {
        if ($this->isTranslationReady) {
            return true;
        }

        if (null === $this->document->documentElement) {
            // Empty or malformed file
            return false;
        }

        // Automated editors have a habit of using XML entity references in the SVG namespace
        // declaration or simply forgetting to set one at all. Both need to be fixed.
        $defaultNS = $this->document->documentElement->lookupnamespaceURI(null);
        if (null === $defaultNS || preg_match('/^(&[^;]+;)+$/', $defaultNS, $match)) {
            // Bad or nonexistent default namespace set, fill in sensible default
            $this->document->documentElement->setAttributeNS(
                'http://www.w3.org/2000/xmlns/',
                'xmlns',
                'http://www.w3.org/2000/svg'
            );
            $defaultNS = 'http://www.w3.org/2000/svg';
        }

        $texts = $this->document->getElementsByTagName('text');
        $textLength = $texts->length;
        if (0 === $textLength) {
            // Nothing to translate!
            return false;
        }

        $styles = $this->document->getElementsByTagName('style');
        $styleLength = $styles->length;
        for ($i = 0; $i < $styleLength; $i++) {
            $style = $styles->item($i);
            $CSS = $style->textContent;
            if (false !== strpos($CSS, '#')) {
                if (!preg_match('/^([^{]+\{[^}]*\})*[^{]+$/', $CSS)) {
                    // Can't easily understand the CSS to check it, so exit
                    return false;
                }
                $selectors = preg_split('/\{[^}]+\}/', $CSS);
                foreach ($selectors as $selector) {
                    if (false !== strpos($selector, '#')) {
                        // IDs in CSS will break when we clone things, should be classes
                        return false;
                    }
                }
            }
        }

        if (0 !== $this->document->getElementsByTagName('tref')->length) {
            // Tref tags not (yet) supported
            return false;
        }

        // Strip empty tspans, texts, fill $idsInUse
        $idsInUse = [ 0 ];
        $translatableNodes = [];
        $tspans = $this->document->getElementsByTagName('tspan');
        $texts = $this->document->getElementsByTagName('text');
        foreach ($tspans as $tspan) {
            if ($tspan->childNodes->length > 1
                || ( 1 == $tspan->childNodes->length && XML_TEXT_NODE !== $tspan->childNodes->item(0)->nodeType )
            ) {
                return false; // Nested tspans not (yet) supported
            }
            $translatableNodes[] = $tspan;
        }

        /** @var DOMElement $text */
        foreach ($texts as $text) {
            // Everything in a <text> should be a <tspan>s, otherwise we can't translate it
            for ($i = 0; $i < count($text->childNodes); $i++) {
                $node = $text->childNodes[$i];
                if ('tspan' === $node->nodeName) {
                    continue;
                }
                if (XML_TEXT_NODE !== $node->nodeType) {
                    // Anything but tspans and text nodes is unexpected
                    return false;
                }
                if ('' === trim($node->nodeValue)) {
                    // Don't bother with whitespace-only nodes
                    continue;
                }
                // Wrap text in <tspan>
                $tspan = $this->document->createElement('tspan');
                $text->replaceChild($tspan, $node);
                $tspan->appendChild($node);
                $translatableNodes[] = $tspan;
            }
            $translatableNodes[] = $text;
        }
        foreach ($translatableNodes as $translatableNode) {
            /** @var DOMElement $translatableNode */
            if ($translatableNode->hasAttribute('id')) {
                $id = trim($translatableNode->getAttribute('id'));
                $translatableNode->setAttribute('id', $id);
                if (false !== strpos($id, '|') || false !== strpos($id, '/')) {
                    // Will cause problems later
                    return false;
                }
                if (preg_match('/^trsvg([0-9]+)/', $id, $matches)) {
                    $idsInUse[] = $matches[1];
                }
                if (is_numeric($id)) {
                    $translatableNode->removeAttribute('id');
                }
            }
            if (!$translatableNode->hasChildNodes()) {
                // Empty tag, will just confuse translators if we leave it in
                $translatableNode->parentNode->removeChild($translatableNode);
            }
        }

        // Reset $translatableNodes
        $translatableNodes = [];
        $tspans = $this->document->getElementsByTagName('tspan');
        $texts = $this->document->getElementsByTagName('text');
        foreach ($tspans as $tspan) {
            array_push($translatableNodes, $tspan);
        }
        foreach ($texts as $text) {
            array_push($translatableNodes, $text);
        }

        // Create id attributes for text, tspan nodes missing it
        foreach ($translatableNodes as $translatableNode) {
            if (!$translatableNode->hasAttribute('id')) {
                $newId = max($idsInUse) + 1;
                $translatableNode->setAttribute('id', 'trsvg'.$newId);
                $idsInUse[] = $newId;
            }
        }

        $textLength = $this->document->getElementsByTagName('text')->length;
        for ($i = 0; $i < $textLength; $i++) {
            /** @var DOMElement $text */
            $text = $this->document->getElementsByTagName('text')->item($i);

            // Text strings like $1, $2 will cause problems later because
            // self::replaceIndicesRecursive() will try to replace them
            // with (non-existent) child nodes.
            if (preg_match('/$[0-9]/', $text->textContent)) {
                return false;
            }

            // Sort out switches
            if ('switch' !== $text->parentNode->nodeName
                && 'svg:switch' !== $text->parentNode->nodeName
            ) {
                // Every text should now be in a switch
                $switch = $this->document->createElementNS($defaultNS, 'switch');
                $text->parentNode->insertBefore($switch, $text);
                // Move node into new sibling <switch> element
                $switch->appendChild($text);
            }

            // Non-translatable style elements on texts get lost, so bump up to switch
            if ($text->hasAttribute('style')) {
                $style = $text->getAttribute('style');
                $text->parentNode->setAttribute('style', $style);
            }

            $numChildren = $text->childNodes->length;
            for ($j = 0; $j < $numChildren; $j++) {
                $child = $text->childNodes->item($j);
                if (XML_TEXT_NODE !== $child->nodeType
                    && 'tspan' !== $child->nodeName
                    && 'svg:tspan' !== $child->nodeName
                ) {
                    // Tags other than tspan inside text tags are not (yet) supported
                    return false;
                }
            }
        }

        $switchLength = $this->document->getElementsByTagName('switch')->length;
        for ($i = 0; $i < $switchLength; $i++) {
            $switch = $this->document->getElementsByTagName('switch')->item($i);
            $siblings = $switch->childNodes;
            foreach ($siblings as $sibling) {
                /** @var DOMElement $sibling */

                $languagesPresent = [];
                if (XML_TEXT_NODE === $sibling->nodeType) {
                    if ('' !== trim($sibling->textContent)) {
                        // Text content inside switch but outside text tags is awkward.
                        return false;
                    }
                    continue;
                } elseif (XML_ELEMENT_NODE !== $sibling->nodeType) {
                    // Only text tags are allowed inside switches
                    return false;
                }

                if ('text' !== $sibling->nodeName && 'svg:text' !== $sibling->nodeName) {
                    return false;
                }

                $language = $sibling->hasAttribute('systemLanguage') ?
                    $sibling->getAttribute('systemLanguage') : 'fallback';
                $realLangs = preg_split('/, */', $language);
                foreach ($realLangs as $realLang) {
                    if (in_array($realLang, $languagesPresent)) {
                        // Two tags for the same language
                        return false;
                    }
                    $languagesPresent[] = $realLang;
                }
                if (1 === count($realLangs)) {
                    continue;
                }
                foreach ($realLangs as $realLang) {
                    // Although the SVG spec supports multi-language text tags (e.g. "en,fr,de")
                    // these are a really poor idea since (a) they are confusing to read and (b) the
                    // desired translations could diverge at any point. So get rid.
                    $singleLanguageNode = $sibling->cloneNode(true);
                    $singleLanguageNode->setAttribute('systemLanguage', $realLang);

                    // @todo: Should also go into tspans and change their ids, too.
                    // $prefix = implode( '-', explode( '-', $singleLanguageNode->getAttribute( 'id' ), -1 ) );
                    // $singleLanguageNode->setAttribute( 'id', "$prefix-$realLang" );

                    // Add in new element
                    $switch->appendChild($singleLanguageNode);
                }
                $switch->removeChild($sibling);
            }
        }

        $this->reorderTexts();

        $this->isTranslationReady = true;
        return true;
    }

    /**
     * Analyse the SVG file, extracting translations and other metadata. Expects the file to
     * be in a certain format: see self::makeTranslationReady() for details.
     */
    protected function analyse(): void
    {
        $switches = $this->document->getElementsByTagName('switch');
        $number = $switches->length;
        $translations = [];
        $this->filteredTextNodes = []; // Reset
        $this->savedLanguages = [];

        for ($i = 0; $i < $number; $i++) {
            /** @var DOMElement $switch */
            $switch = $switches->item($i);

            $texts = $switch->getElementsByTagName('text');
            $count = $texts->length;
            if (0 === $count) {
                continue;
            }
            $fallback = $this->xpath->query(
                "text[not(@systemLanguage)]|svg:text[not(@systemLanguage)]",
                $switch
            );
            if (0 === $fallback->length) {
                // Some sort of deep hierarchy, can't translate
                continue;
            }

            /** @var DOMElement $fallbackText */
            $fallbackText = $fallback->item(0);
            $fallbackTextId = $fallbackText->getAttribute('id');

            for ($j = 0; $j < $count; $j++) {
                // Don't want to manipulate actual node
                /** @var DOMElement $actualNode */
                $actualNode = $texts->item($j);
                /** @var DOMElement $text */
                $text = $actualNode->cloneNode(true);
                $numChildren = $text->childNodes->length;
                $hasActualTextContent = self::hasActualTextContent($text);
                $lang = $text->hasAttribute('systemLanguage') ? $text->getAttribute('systemLanguage') : 'fallback';
                $langCode = str_replace('_', '-', strtolower($lang));

                $counter = 1;
                for ($k = 0; $k < $numChildren; $k++) {
                    $child = $text->childNodes->item($k);
                    if (XML_ELEMENT_NODE === $child->nodeType) {
                        // Per the checks in makeTranslationReady() this is a tspan so
                        // register it as a child node.

                        /** @var DOMElement $childTspan */
                        $childTspan = $fallbackText->getElementsByTagName('tspan')->item($counter - 1);
                        if (!$childTspan) {
                            continue;
                        }

                        $childId = $childTspan->getAttribute('id');
                        $translations[$childId][$langCode] = $this->nodeToArray($child);
                        $translations[$childId][$langCode]['data-parent'] = $fallbackTextId;
                        if ($text->hasAttribute('data-children')) {
                            $existing = $text->getAttribute('data-children');
                            $text->setAttribute('data-children', "$existing|$childId");
                        } else {
                            $text->setAttribute('data-children', $childId);
                        }

                        // Replace with $1, $2 etc.
                        $text->replaceChild($this->document->createTextNode('$'.$counter), $child);
                        $counter++;
                    }
                }
                if ($hasActualTextContent) {
                    // If the <text> has *its own* text content, rather than just <tspan>s, register it
                    // for translation.
                    $translations[$fallbackTextId][$langCode] = $this->nodeToArray($text);
                } else {
                    $this->filteredTextNodes[$fallbackTextId][$langCode] = $this->nodeToArray($text);
                }
                $savedLang = 'fallback' === $langCode ? $this->fallbackLanguage : $langCode;
                $this->savedLanguages[] = $savedLang;
            }
        }
        $this->inFileTranslations = $translations;
        $this->savedLanguages = array_unique($this->savedLanguages);
    }

    /**
     * Returns a list of translations present in the loaded file, in the following format:
     *
     *   'message id' => [
     *     'language code' => [
     *       'text' => 'Translatable message',
     *       'id' => 'foo',
     *       ...
     *       (other <text> or <tspan> attributes)
     *
     * @return mixed[]
     */
    public function getInFileTranslations(): array
    {
        if (null === $this->inFileTranslations) {
            $this->analyse();
        }
        return $this->inFileTranslations;
    }

    /**
     * Set translations for a single language.
     * @param string $lang Language code of the translations being provided.
     * @param string[] $translations Array of tspan-IDs to message texts.
     * @return string[][]
     */
    public function setTranslations(string $lang, array $translations): array
    {
        // Load the existing translation structure, and go through it swapping the messages.
        $inFileTranslations = $this->getInFileTranslations();
        $filteredTextNodes = $this->getFilteredTextNodes();
        foreach ($translations as $tspanId => $msg) {
            // Set up the tspan node (including adding in the new message).
            if (!isset($inFileTranslations[$tspanId][$lang])) {
                $inFileTranslations[$tspanId][$lang] = $inFileTranslations[$tspanId]['fallback'];
                $inFileTranslations[$tspanId][$lang]['id'] .= "-$lang";
            }
            if (empty($msg)) {
                continue;
            }
            $inFileTranslations[$tspanId][$lang]['text'] = $msg;
            // Set up the text node (if this is a new language).
            $textId = $inFileTranslations[$tspanId][$lang]['data-parent'];
            if (!isset($filteredTextNodes[$textId][$lang])) {
                $filteredTextNodes[$textId][$lang] = $filteredTextNodes[$textId]['fallback'];
                $filteredTextNodes[$textId][$lang]['id'] .= "-$lang";
            }
        }
        $allTranslations = array_merge($inFileTranslations, $filteredTextNodes);
        // Add the updated translations back into the file.
        return $this->switchToTranslationSet($allTranslations);
    }

    /**
     * Return a list of languages which have one or more translations in-file.
     *
     * @return string[]
     */
    public function getSavedLanguages(): array
    {
        if (null === $this->savedLanguages) {
            $this->analyse();
        }
        return $this->savedLanguages;
    }

    /**
     * Get a list of languages which have one or more translations in-file
     *
     * @return string[][] Array of languages, split into 'full' and 'partial' subarrays
     */
    public function getSavedLanguagesFiltered(): array
    {
        $translations = $this->getInFileTranslations();
        $savedLanguages = $this->getSavedLanguages();

        $full = [];
        $partial = [];
        foreach ($savedLanguages as $savedLanguage) {
            $fullSoFar = true;
            foreach ($translations as $languages) {
                if (!isset($languages[$savedLanguage])) {
                    $fullSoFar = false;
                    break;
                }
            }
            if ($fullSoFar || $savedLanguage == $this->fallbackLanguage) {
                $full[] = $savedLanguage;
            } else {
                $partial[] = $savedLanguage;
            }
        }
        return [ 'full' => $full, 'partial' => $partial ];
    }

    /**
     * Returns an array of <text> nodes that contain only child elements.
     *
     * @return mixed[] The same message ID => language code => attributes mapping as in getInFileTranslations()
     */
    public function getFilteredTextNodes(): array
    {
        if (null === $this->filteredTextNodes) {
            $this->analyse();
        }
        return $this->filteredTextNodes;
    }

    /**
     * Compile an updated DOM model of the SVG using the provided set of translations
     *
     * @param array $translations
     * @return string[][] Array with keys 'expanded' and 'started', each an array of language names
     */
    public function switchToTranslationSet(array $translations): array
    {
        $currentLanguages = $this->getSavedLanguages();
        $expanded = $started = [];

        $switches = $this->document->getElementsByTagName('switch');
        $number = $switches->length;
        for ($i = 0; $i < $number; $i++) {
            $switch = $switches->item($i);
            $fallback = $this->xpath->query(
                "text[not(@systemLanguage)]|svg:text[not(@systemLanguage)]",
                $switch
            );
            if (0 === $fallback->length) {
                // Some sort of deep hierarchy, can't translate
                continue;
            }

            /** @var DOMElement $fallbackText */
            $fallbackText = $fallback->item(0);
            $textId = $fallbackText->getAttribute('id');

            foreach ($translations[$textId] as $language => $translation) {
                // Sort out systemLanguage attribute
                if ('fallback' !== $language) {
                    $translation['systemLanguage'] = self::langCodeToOs($language);
                }

                // Prepare an array of "children" (sub-messages)
                $children = [];
                if (isset($translation['data-children'])) {
                    $children = explode('|', $translation['data-children']);
                    foreach ($children as &$child) {
                        if (isset($translations[$child][$language])) {
                            $child = $translations[$child][$language];
                        } else {
                            $child = $translations[$child]['fallback'];
                        }
                        $child = $this->arrayToNode($child, 'tspan');
                    }
                }

                // Set up text tag
                $text = $translation['text'];
                unset($translation['text']);
                $newTextTag = $this->arrayToNode($translation, 'text');

                // Add text, replacing $1, $2 etc. with translations
                $this->replaceIndicesRecursive($text, $children, $newTextTag, $this->document);

                // Put text tag into document
                $path = 'fallback' === $language ?
                    "svg:text[not(@systemLanguage)]|text[not(@systemLanguage)]" :
                    "svg:text[@systemLanguage='$language']|text[@systemLanguage='$language']";
                $existing = $this->xpath->query($path, $switch);
                if (1 == $existing->length) {
                    // Only one matching text node, replace if different
                    if ($this->nodeToArray($newTextTag) === $this->nodeToArray($existing->item(0))) {
                        continue;
                    }
                    $switch->replaceChild($newTextTag, $existing->item(0));
                } elseif (0 == $existing->length) {
                    // No matching text node for this language, so we'll create one
                    $switch->appendChild($newTextTag);
                }

                // To have got this far, we must have either updated or started a new language
                $langName = $this->fetchLanguageName($language, $this->fallbackLanguage);
                if (in_array($language, $currentLanguages) || 'fallback' == $language) {
                    $expanded[] = $langName;
                } else {
                    $started[] = $langName;
                }
            }
        }
        $this->reorderTexts();

        return [
            'started' => array_unique($started),
            'expanded' => array_unique($expanded),
        ];
    }

    /**
     * Implement our own wrapper around Language::fetchLanguageName, providing a more sensible
     * fallback chain and our own interpretation of the "fallback" language code.
     *
     * @param string $langCode Language code (e.g. en-gb, fr)
     * @param string $fallbackLanguage Code of the language for which the "fallback" magic word is equivalent
     * @return string The autonym of the language with that code (English, français, Nederlands)
     */
    private function fetchLanguageName(string $langCode, string $fallbackLanguage): string
    {
        $intuition = new Intuition();
        $langCode = 'fallback' === $langCode ? $fallbackLanguage : $langCode;
        $langName = $intuition->getLangName($langCode);
        if ('' == $langName) {
            // Try searching for prefix only instead
            preg_match('/^([a-z]+)/', $langCode, $matches);
            $langName = $intuition->getLangName($matches[0]);
        }
        if ('' == $langName) {
            // Okay, seems the best we can do is return the language code
            $langName = $langCode;
        }
        return $langName;
    }

    /**
     * Export the SVG as a string, i.e. as "<?xml version...</svg>"
     *
     * @return string
     */
    public function saveToString(): string
    {
        // Could have simply overridden __toString() but probably not a good idea with
        // no clear benefit.
        return $this->document->saveXML();
    }

    /**
     * Export the SVG to the desired filepath
     *
     * @param string $path
     * @return int|bool The number of bytes written or false if an error occurred.
     */
    public function saveToPath(string $path)
    {
        return $this->document->save($path);
    }

    /**
     * One of several functions used to convert between TranslateSvg's
     * three main formats for handling data (nodes, translations and arrays).
     * This one converts between the node and array translation. The function
     * assumes that the node does not have any child nodes that need to be
     * converted.
     *
     * @param DOMNode $node A DOMNode object (probably a <text> or <tspan>)
     * @return string[] An associative array of properties, including 'text'
     */
    public function nodeToArray(DOMNode $node): array
    {
        $array = [ 'text' => $node->textContent ];
        $attributes = $node->hasAttributes() ? $node->attributes : [];
        foreach ($attributes as $attribute) {
            $prefix = '' === $attribute->prefix ? '' : ( $attribute->prefix.':' );
            if ('space' === $attribute->name) {
                // XML namespace prefix seems to disappear: TODO?
                $prefix = 'xml:';
            }
            [ $attrName, $attrValue ] = Transformations::mapFromAttribute(
                $prefix.$attribute->name,
                $attribute->value
            );
            if (false === $attrName || false === $attrValue) {
                continue;
            }
            [ $attrName, $attrValue ] = Transformations::mapToAttribute($attrName, $attrValue);
            if (false === $attrName || false === $attrValue) {
                continue;
            }
            $array[ $attrName ] = $attrValue;
        }
        return $array;
    }

    /**
     * One of several functions used to convert between TranslateSvg's
     * three main formats for handling data (nodes, translations and arrays).
     * This one converts between the array and node formats.
     *
     * @param array $array An associative array of properties, inc 'text'
     * @param string $nodeName (optional) The name of the node (no <>), default 'text'
     * @return DOMNode A new DOMNode ready to be inserted, complete with text child
     */
    public function arrayToNode(array $array, string $nodeName = 'text'): DOMNode
    {
        $defaultNS = $this->document->documentElement->lookupnamespaceURI(null);
        $newNode = $this->document->createElementNS($defaultNS, $nodeName);

        // Handle the text property first...
        if (isset($array['text'])) {
            $textContent = $this->document->createTextNode($array['text']);
            $newNode->appendChild($textContent);
            unset($array['text']);
        }

        // ...then all other properties
        foreach ($array as $attrName => $attrValue) {
            if (false !== $attrName && !preg_match('/^data\-/', $attrName)) {
                $newNode->setAttribute($attrName, $attrValue);
            }
        }
        return $newNode;
    }

    /**
     * Checks whether a given DOMNode has some non-negligible text content (as
     * opposed to just whitespace or other tags. Whitespace *between* tags
     * counts, as it does get rendered.
     *
     * @param DOMNode $node The node to check for text content
     * @return bool True if content found, false if not
     */
    public static function hasActualTextContent(DOMNode $node): bool
    {
        // No text nodes means no text content
        if (!$node->hasChildNodes()) {
            return false;
        }

        // Search child nodes looking for matching content
        $children = $node->childNodes;
        $numChildren = $children->length;
        for ($i = 0; $i < $numChildren; $i++) {
            if (XML_TEXT_NODE == $children->item($i)->nodeType) {
                // Whitespace at beginning and end doesn't count, but
                // otherwise we have a match
                if (!0 === $i || $i === ( $numChildren - 1 )
                    || !0 === strlen(trim($children->item($i)->textContent))
                ) {
                    return true;
                }
            }
        }

        // Didn't find any
        return false;
    }

    /**
     * Recursively replaces $1, $2, etc. with text tags, if required. Text content
     * is formalised as actual text nodes
     *
     * @param string $text The text to search for $1, $2 etc.
     * @param array &$newNodes An array of DOMNodes, indexed by which $ number they represent
     * @param DOMNode &$parentNode A node to fill with the generated content
     * @param DOMDocument $document Base document to use
     * @return void
     */
    public static function replaceIndicesRecursive(
        string $text,
        array &$newNodes,
        DOMNode &$parentNode,
        DOMDocument $document
    ): void {
        // If nothing to replace, just fire back a text node
        if (0 === count($newNodes)) {
            if (strlen($text) > 0) {
                $parentNode->appendChild($document->createTextNode($text));
            }
        }

        // Otherwise, loop through $1, $2, etc. replacing each
        preg_match_all('/\$([0-9]+)/', $text, $matches);
        foreach (array_keys($newNodes) as $index) {
            // One-indexed (no $0)
            $realIndex = $index + 1;
            if (!in_array($realIndex, $matches[1]) || !isset($newNodes[$index])) {
                // Sanity check
                continue;
            }
            [ $before, $after ] = preg_split('/\$'.$realIndex.'(?=[^0-9]|$)/', $text);
            $newNodeToProcess = $newNodes[$index];
            unset($newNodes[$index]);
            self::replaceIndicesRecursive($before, $newNodes, $parentNode, $document);
            $parentNode->appendChild($newNodeToProcess);
            self::replaceIndicesRecursive($after, $newNodes, $parentNode, $document);
        }
    }

    protected function reorderTexts(): void
    {
        // Move sublocales to the beginning of their switch elements
        $sublocales = $this->xpath->query(
            "//text[contains(@systemLanguage,'_')]"."|"."//svg:text[contains(@systemLanguage,'_')]"
        );
        $count = $sublocales->length;
        for ($i = 0; $i < $count; $i++) {
            $firstSibling = $sublocales->item($i)->parentNode->childNodes->item(0);
            $sublocales->item($i)->parentNode->insertBefore($sublocales->item($i), $firstSibling);
        }

        // Move fallbacks to the end of their switch elements
        $fallbacks = $this->xpath->query(
            "//text[not(@systemLanguage)]"."|"."//svg:text[not(@systemLanguage)]"
        );
        $count = $fallbacks->length;
        for ($i = 0; $i < $count; $i++) {
            $fallbacks->item($i)->parentNode->appendChild($fallbacks->item($i));
        }
    }

    /**
     * @param string $langCode
     * @return string
     */
    private static function langCodeToOs(string $langCode): string
    {
        if (false === strpos($langCode, '-')) {
            // No territory specified, so no change to make (fr => fr)
            return $langCode;
        }
        [ $prefix, $suffix ] = explode('-', $langCode, 2);
        return $prefix.'_'.strtoupper($suffix);
    }
}
