#!/usr/local/bin/php72

<?php
// convenience function
function findXpath(DOMDocument $dom, String $xpath) : DOMNodelist
{
    $objXpath = new DOMXPath($dom);
    return $objXpath->query($xpath);
}

/*
 * Alternative to getNodePath.
 * This one uses the ID column to uniquely identify nodes,
 * if option is set.
*/
function getXpath(DOMNode $domNode) : String
{
    switch(get_class($domNode))
    {
        case "DOMElement":
            $idAttribute = $GLOBALS['idAttribute'];
            if(strlen($idAttribute) > 0)
            {
                $attrValue = $domNode->getAttribute("$idAttribute");
            }
            $attr = ($attrValue !== "") ? "[@{$idAttribute}='{$attrValue}']" : "";
        
            if(is_null($domNode->parentNode))
            {
                return "/" . $domNode->tagName . $attr;
            }
            else
            {
                return getXpath($domNode->parentNode) . "/" . $domNode->tagName . $attr;
            }
            break;
        case "DOMDocument":
            return "";
            break;
        case "DOMText":
            return getXpath($domNode->parentNode) . "/text()";
            break;
        default:
            echo "\nFAIL: getXPath class " . get_class($domNode) . " path " . $domNode->getNodePath() . "\n";
            break;
    }
}

/*
 * Find a match for dom2Node, which belongs to dom2, in dom1
 * A match is found, if the XPath of the node has exactly one
 * match in both DOMs.
 * Returns the matching node in dom1, or NULL if not match was found. 
*/
function findMatch(DOMDocument $dom1, DOMDocument $dom2, DOMNode $dom2Node) : ?DOMNode
{
    // todo: Jedes Element auf dem Pfad muss auf ID überprüft werden
    $attrValue = "";
    $idAttribute = $GLOBALS['idAttribute'];
    if(strlen($idAttribute) > 0 && $dom2Node instanceof DOMElement)
    {
        $attrValue = $dom2Node->getAttribute($idAttribute);
    }
    $attr = ($attrValue !== "") ? "[@{$idAttribute}='{$attrValue}']" : "";
    //$xpath = $dom2Element->getNodePath() . $attr;
    // own solution using ID attribute if applicable
    $xpath = getXpath($dom2Node);
    $m1 = findXpath($dom1, $xpath);
    $m2 = findXpath($dom2, $xpath);

    if(count($m1) === 1 && count($m2) === 1 &&
        $m1[0] !== null && $m2[0] !== null)
    {
        return $m1[0];
    }
    else
    {
        return null;
    }
}

/*
 * This is where the real work is done.
 * Merges node addElement of DOM add into DOM dom.
 * Manipulates dom.
*/
function merge(DOMDocument $dom, DOMDocument $add, DOMNode $addElement = null) : void
{
    // starting point is the document itself
    if(null === $addElement)
    {
        $addElement = $add->documentElement;
    }

    $classname = get_class($addElement);
    //echo "Class {$classname}\n";
    switch ($classname)
    {
        case ("DOMDocument"):
            merge($dom, $add, $addElement->documentElement);
            break;
        case ("DOMElement"):
            // do we have a delete attribute from options?
            $delete = false;
            $deleteAttribute = $GLOBALS['deleteAttribute'];
            if(strlen($deleteAttribute) > 0 &&
                $addElement->getAttribute($deleteAttribute) !== "")
            {
                $delete = true;
            }
            
            $xp = findMatch($dom, $add, $addElement);
            if(null === $xp && !$delete)
            {
                // no match found, add whole element to dom
                $node = findXpath($dom, getXpath($addElement->parentNode))[0];
                $addElementClone = $dom->importNode($addElement, true);
                $node->appendChild($addElementClone);
            }
            else // match found
            {
                if($delete)
                {
                    $xp->parentNode->removeChild($xp);
                }
                else
                {
                    // merge all children
                    for($i=0; $i < $addElement->childNodes->length; $i++)
                    {
                        $cn = $addElement->childNodes->item($i);
                        merge($dom, $add, $cn);
                    }
                    // merge all attributes
                    if ($addElement->hasAttributes())
                    {
                        foreach ($addElement->attributes as $attr)
                        {
                            merge($dom, $add, $attr);
                        }
                    }
                }
            }
            break;
        case ("DOMAttr"):
            /*
             * copy attribute to matching node in dom.
             * there must be a match, since the element has already been matched.
             * setting the attribute will create a new one or overwrite
             * an existing one.
             */
            $xp = findXpath($dom, getXpath($addElement->ownerElement))[0];
            $xp->setAttribute($addElement->name, $addElement->value);
            break;
        case ("DOMNodeList"):
            // calculated field, hence we store the value for performane
            $n = $addElement->length;
            //echo "dom node list has {$n} items\n";
            for ($i = 0; $i < $n; $i++)
            {
                //echo "dom node list item #{$i}\n";
                merge($dom, $add, $addElement->item($i));
            }
            break;
        case ("DOMText"):
            /*
             * empty text will not be merged.
             * if no match is found, the whole node will be copied over,
             * otherwise we create a new text node and replace the old one,
             * because you cannot set the text in an existing text node.
             */
            $xp = findMatch($dom, $add, $addElement);
            if(null === $xp)
            {
                if(trim($addElement->wholeText) !== "")
                {
                    // add whole element to dom
                    $node = findXpath($dom, getXpath($addElement->parentNode))[0];
                    $addElementClone = $dom->importNode($addElement, true);
                    $node->appendChild($addElementClone);
                }
            }
            else
            {
                $txt = trim($addElement->wholeText);
                $newTextNode = $dom->createTextNode($txt);
                $xp->parentNode->replaceChild($newTextNode, $xp);
            }
            break;
        case ("DOMComment"):
            // do not process comments
            break;
        default:
            // should never happen
            if($classname)
            {
                exit("Abort: instance of unknown class: $classname\n");
            }
            else
            {
                exit("Abort: no XML found\n");
            }
    }
}

/*
 * Main Program
 */

if(count($argv) < 3)
{
    echo "Usage: {$argv[0]} [-o] <inputXML1> <inputXML2> [inputXML3..N] <outputXML>\n
    -o: overwrite existing output file
    -id: specifies the id attribute to identify unique elements
    -d: dpecified the attribute that indicated that an element should be deleted";
    exit(-1);
}

// evaluate options
// options will be stored in global variables
$overwrite = false;
$idAttribute = "";
$deleteAttribute = "";
while(substr($argv[1], 0, 1) === "-")
{
    if($argv[1] === "-o")
    {
        $overwrite = true;
        array_shift($argv);
    }

    else if($argv[1] === "-id")
    {
        $idAttribute = $argv[2];
        array_shift($argv);
        array_shift($argv);
    }

    else if($argv[1] === "-d")
    {
        $deleteAttribute = $argv[2];
        array_shift($argv);
        array_shift($argv);
    }

    else
    {
        echo "Unknow option: {$argv[1]}\n";
        exit(-10);
    }
}

// test input files
for ($i = 1; $i < count($argv) - 1; $i++)
{
    if(!file_exists($argv[$i]))
    {
        echo "Input file '{$argv[$i]}' does not exist.\n";
        exit(-2);
    }
}

// test outut file
if(!$overwrite && file_exists($argv[count($argv) - 1]))
{
    echo "Output file '{$argv[count($argv) - 1]}' already exists.\n";
    exit(-3);
}

//echo "Delete attribute: {$deleteAttribute}\n";
//echo "ID attribute: {$idAttribute}\n";

// load 1st XML
echo "Loading file {$argv[1]} ... ";
$mainDom = new DomDocument();
// pretty formatting later
$mainDom->preserveWhiteSpace = false;
$mainDom->formatOutput = true;
$mainDom->load($argv[1]);
echo "Done\n";

// load and merge all other files
for ($i = 2; $i < count($argv) - 1; $i++)
{
    echo "Loading file {$argv[$i]} ... ";
    $dom = new DomDocument();
	$dom->load($argv[$i]);
    // merge dom into mainDom
    echo "Merging ... ";
    merge($mainDom, $dom);
    echo "Done\n";
}

echo "Processed all files\n";

// write to file
echo "Writing output file {$argv[count($argv) - 1]} ... ";
/* does not work here
$mainDom->preserveWhiteSpace = false;
$mainDom->formatOutput = true;
*/
$mainDom->save($argv[count($argv) - 1]);
echo "Done\n";

exit(0);
?>
