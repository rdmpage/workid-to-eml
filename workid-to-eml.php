<?php

// Take a DOI for a paper supplied as a parameter, and output a stub EML for a GBIF dataset 
// based on that paper.

error_reporting(E_ALL);

require_once(__DIR__ . '/vendor/autoload.php');

use Symfony\Component\Yaml\Yaml;
use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\CiteProc;

//----------------------------------------------------------------------------------------
function get($url, $format = '')
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	
	if ($format != '')
	{
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: " . $format));	
	}
	
	$response = curl_exec($ch);
	if($response == FALSE) 
	{
		$errorText = curl_error($ch);
		curl_close($ch);
		die($errorText);
	}
	
	$info = curl_getinfo($ch);
	$http_code = $info['http_code'];
	
	curl_close($ch);
	
	return $response;
}

//----------------------------------------------------------------------------------------

$options = getopt("", ["doi:"]);

if (!isset($options['doi'])) {
    fwrite(STDERR, "Usage: php {$argv[0]} --doi=<doi>\n");
    exit(1);
}

$doi = $options['doi'];

// Is it a DOI (and trim any namespace prefix, HTTP resolution, etc.)
if (preg_match('/((DOI:\s*|doi:\s*|https?:\/\/(dx\.)?doi.org\/)?(?<doi>10\.[0-9]{4,}(?:\.[0-9]+)*(?:\/|%2F)(?:(?![\"&\'])\S)+))/', $doi, $m))
{
	$doi = $m['doi'];
}
else
{
    fwrite(STDERR, "$doi is not a DOI\n");
    exit(1);
}

// get DOI metadata
$url = 'https://doi.org/' . $doi;
$csljson = get($url, 'application/vnd.citationstyles.csl+json');

//echo $json;

$obj = json_decode($csljson);

if (json_last_error() !== JSON_ERROR_NONE) 
{
	$code = json_last_error();
	$message = json_last_error_msg();
	
    fwrite(STDERR, "Could not read CSL-JSON, decode error {$code}: {$message}\n");
    exit(1);
}

$data = [];
$data['doi'] = $doi;

// title
if (isset($obj->title))
{
	$data['title'] = $obj->title;
	if (is_array($data['title']))
	{
		$data['title'] = $data['title'][0];
	}
}

// abstract
if (isset($obj->abstract))
{
	$data['abstract'] = strip_tags($obj->abstract);
}

// creators
if (isset($obj->author))
{
	$data['author'] = [];
	
	foreach ($obj->author as $author)
	{
		$creator = [];
		
		if (isset($author->given))
		{
			$creator['givenName'] = $author->given;
		}
		
		if (isset($author->family))
		{
			$creator['surName'] = $author->family;
		}
		
		if (isset($author->ORCID))
		{
			$creator['orcid'] = preg_replace('/https?:\/\/orcid.org\//', '', $author->ORCID);
		}

		$data['author'][] = $creator;
	}
}

// date 
if (isset($obj->issued))
{
	$date = '';
	$d = $obj->issued->{'date-parts'}[0];

	// sanity check
	if (is_numeric($d[0]))
	{
		$datetype = 'date';
	
		if ( count($d) > 0 ) $year = $d[0] ;
		if ( count($d) > 1 ) $month = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[1] ) ;
		if ( count($d) > 2 ) $day = preg_replace ( '/^0+(..)$/' , '$1' , '00'.$d[2] ) ;
		if ( isset($month) and isset($day) )
		{
			$date     = "$year-$month-$day";
			$datetype = 'date';
		}
		else if ( isset($month) )
		{
			$date     = "$year-$month";
		}
		else if ( isset($year) ) 
		{
			$date     = "$year";
		}
		
		$data['date'] = $date;
	}				
}			

// license
if (isset($obj->license))
{
	$data['license'] = [];
	if (is_array($obj->license))
	{
		foreach ($obj->license as $license)
		{
			if (isset($license->URL))
			{
				$data['license'][] = $license->URL;
			}
		}
	}		
	$data['license'] = array_unique($data['license']);
}

// formatted bibliography
$format = 'apa';
$style_sheet = StyleSheet::loadStyleSheet($format);
$citeProc = new CiteProc($style_sheet);
$citation = strip_tags($citeProc->render([$obj], "bibliography"));
$citation = preg_replace('/\R/u', '', $citation);
$citation = trim($citation);

$reference = [];
$reference['citation'] = $citation;
$reference['identifier'] = 'https://doi.org/' . $data['doi'];

$data['bibliography'][] = $reference ;

// print_r($data);

// write work metadata as EML

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;

$eml_ns = 'eml://ecoinformatics.org/eml-2.1.1';
$dc_ns  = 'http://purl.org/dc/terms/';
$xsi_ns = 'http://www.w3.org/2001/XMLSchema-instance';
$xml_ns = 'http://www.w3.org/XML/1998/namespace';

$root = $dom->createElementNS($eml_ns, 'eml:eml');
$dom->appendChild($root);

// namespace declarations
$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dc', $dc_ns);
$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $xsi_ns);

// xsi:schemaLocation
$root->setAttributeNS(
    $xsi_ns,
    'xsi:schemaLocation',
    'eml://ecoinformatics.org/eml-2.1.1 http://rs.gbif.org/schema/eml-gbif-profile/1.1/eml.xsd'
);

$root->setAttribute('packageId', '');
$root->setAttribute('system', 'http://gbif.org');
$root->setAttribute('scope', 'system');

$dataset = $dom->createElement('dataset');
$root->appendChild($dataset);

if (isset($data['doi']))
{
	// DOI of paper (do we need "doi:" prefix?)
	$alternateIdentifier = $dom->createElement('alternateIdentifier');
	$alternateIdentifier->appendChild($dom->createTextNode($data['doi']));
	$dataset->appendChild($alternateIdentifier);
}

// title
if (isset($data['title']))
{
	$title = $dom->createElement('title');
	$title->setAttributeNS($xml_ns, 'xml:lang', 'eng'); // assume
	$title->appendChild($dom->createTextNode($data['title']));
	$dataset->appendChild($title);
}

// creators
foreach ($data['author'] as $author)
{
	$creator = $dom->createElement('creator');
	$dataset->appendChild($creator);
	
	// example creator content
	$individualName = $dom->createElement('individualName');
	
	if (isset($author['givenName']))
	{	
		$individualName->appendChild($dom->createElement('givenName', $author['givenName']));
	}
	if (isset($author['surName']))
	{	
		$individualName->appendChild($dom->createElement('surName', $author['surName']));
	}
	$creator->appendChild($individualName);
	
	if (isset($author['orcid']))
	{
		$userId = $dom->createElement('userId');
		$userId->setAttribute('directory', 'http://orcid.org/'); // assume
		$userId->appendChild($dom->createTextNode($author['orcid']));
		$creator->appendChild($userId);	
	}
}

// metadataProvider (me)
$metadataProvider = $dom->createElement('metadataProvider');
$dataset->appendChild($metadataProvider);

$individualName = $dom->createElement('individualName');
$metadataProvider->appendChild($individualName);

$givenName = $dom->createElement('givenName');
$givenName->appendChild($dom->createTextNode('Roderic'));
$individualName->appendChild($givenName);

$surName = $dom->createElement('surName');
$surName->appendChild($dom->createTextNode('Page'));
$individualName->appendChild($surName);

$electronicMailAddress = $dom->createElement('electronicMailAddress');
$electronicMailAddress->appendChild($dom->createTextNode('rdmpage@gmail.com'));
$metadataProvider->appendChild($electronicMailAddress);

$userId = $dom->createElement('userId');
$userId->setAttribute('directory', 'http://orcid.org/'); // assume
$userId->appendChild($dom->createTextNode('0000-0002-7101-9767'));
$metadataProvider->appendChild($userId);

// pubDate
$pubDate = $dom->createElement('pubDate');
$pubDate->appendChild($dom->createTextNode($data['date']));
$dataset->appendChild($pubDate);

// abstract
$abstract_text = "Dataset from " . $data['doi'];
if (isset($data['abstract']))
{
	$abstract_text = $data['abstract'];
}

$abstract = $dom->createElement('abstract');
$dataset->appendChild($abstract);

$para = $dom->createElement('para');
$para->appendChild($dom->createTextNode($abstract_text));
$abstract->appendChild($para);

// license
if (isset($data['license']))
{
	$intellectualRights = $dom->createElement('intellectualRights');
	$dataset->appendChild($intellectualRights);
	
	$para = $dom->createElement('para');
	$intellectualRights->appendChild($para);	
	
	$fragment = null;
	
	// need cleaver way to dela with the mess that is licensing
	switch ($data['license'][0])
	{
		case 'https://creativecommons.org/licenses/by/4.0':
		default:
			$fragment = $dom->createDocumentFragment();

			$fragment->appendXML(
				'This work is licensed under a '
				. '<ulink url="' . $data['license'][0] . '">'
				. '<citetitle>Creative Commons Attribution (CC-BY) 4.0 License</citetitle>'
				. '</ulink>.'
			);	
			
			break;
	}
	
	if ($fragment)
	{
		$para->appendChild($fragment);
	}
}

// contact (me)
$contact = $dom->createElement('contact');
$dataset->appendChild($contact);

$individualName = $dom->createElement('individualName');
$contact->appendChild($individualName);

$givenName = $dom->createElement('givenName');
$givenName->appendChild($dom->createTextNode('Roderic'));
$individualName->appendChild($givenName);

$surName = $dom->createElement('surName');
$surName->appendChild($dom->createTextNode('Page'));
$individualName->appendChild($surName);

$electronicMailAddress = $dom->createElement('electronicMailAddress');
$electronicMailAddress->appendChild($dom->createTextNode('rdmpage@gmail.com'));
$contact->appendChild($electronicMailAddress);

$userId = $dom->createElement('userId');
$userId->setAttribute('directory', 'http://orcid.org/'); // assume
$userId->appendChild($dom->createTextNode('0000-0002-7101-9767'));
$contact->appendChild($userId);

// bibliography
if (isset($data['bibliography']))
{
	$additionalMetadata = $dom->createElement('additionalMetadata');
	$root->appendChild($additionalMetadata);

	$metadata = $dom->createElement('metadata');
	$additionalMetadata->appendChild($metadata);

	$gbif = $dom->createElement('gbif');
	$metadata->appendChild($gbif);
	
	$dateStamp = $dom->createElement('dateStamp');
	$dateStamp->appendChild($dom->createTextNode(date("c")));
	$gbif->appendChild($dateStamp);
		
	$bibliography = $dom->createElement('bibliography');
	$gbif->appendChild($bibliography);

	foreach ($data['bibliography'] as $reference)
	{
		$citation = $dom->createElement('citation');
		
		if (isset($reference['identifier']))
		{
			$citation->setAttribute('identifier', $reference['identifier']);
		}
		
		$citation->appendChild($dom->createTextNode($reference['citation']));
		$bibliography->appendChild($citation);	
	}
}

echo $dom->saveXML();

?>
