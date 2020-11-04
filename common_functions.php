<?php

/**
 * Removes invalid XML
 *
 * @access public
 * @param string $value
 * @return string
 */
function stripInvalidXml($value)
{
    $ret = "";
    $current;
    if (empty($value)) 
    {
        return $ret;
    }

    $length = strlen($value);
    for ($i=0; $i < $length; $i++)
    {
        $current = ord($value[$i]);
        if (($current == 0x9) ||
            ($current == 0xA) ||
            ($current == 0xD) ||
            (($current >= 0x20) && ($current <= 0xD7FF)) ||
            (($current >= 0xE000) && ($current <= 0xFFFD)) ||
            (($current >= 0x10000) && ($current <= 0x10FFFF)))
        {
            $ret .= chr($current);
        }
        else
        {
            $ret .= " ";
        }
    }
    return $ret;
}

function download_remote_file_with_curl($file_domain, $query_id, $query_param)
{
	$url = $file_domain."?request=getFeature&storedquery_id=".$query_id."&".$query_param;
	// if ($https) $url = "https://".$url;
	
	$headers = array(
            "Accept: application/xhtml+xml,application/xml,text/xml,*/*;q=0.01",
            "Cache-Control: no-cache", 
            "Pragma: no-cache",
			"Connection: keep-alive",
			"Accept-Language: fi-FI,fi;0.9,en-US;q=0.8,en;q=0.6",
			"Charset: UTF-8"
    );
    $options = array(
        CURLOPT_RETURNTRANSFER => true,     // return web page
        CURLOPT_HEADER         => false,    // don't return headers
        CURLOPT_FOLLOWLOCATION => true,     // follow redirects
        CURLOPT_ENCODING       => "",       // handle all encodings
        CURLOPT_USERAGENT      => "spider", // who am i
        CURLOPT_AUTOREFERER    => true,     // set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // timeout on connect
        CURLOPT_TIMEOUT        => 120,      // timeout on response
        CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
        CURLOPT_SSL_VERIFYPEER => false,
    );
	
    $ch = curl_init($url);
    curl_setopt_array( $ch, $options );
	
	$file_content = curl_exec($ch);
	if (strlen($file_content) < 10) {
		error_log($url);
		error_log(json_encode($file_content));
		error_log(json_encode($ch));
	}
	curl_close($ch);
	//$file_content = preg_replace(UTFREGEX, '$1', $file_content);
	//$file_content = stripInvalidXml($file_content);
	
    $doc = new DOMDocument('1.0', 'utf-8');
    $doc->loadXML($file_content);
    return $doc;
    // $elements = $doc->getElementsByTagNameNS('http://www.opengis.net/gml/3.2', 'doubleOrNilReasonTupleList');
    // if ($elements->length == 1) return $elements->item(0)->nodeValue;

    // exit ($file_content);
    // exit ($doc->saveXML());
    
	
}

?>
