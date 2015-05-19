<?php
/* This demo shows you hwo to add a simple signature through the CoSign Signature SOAP API.
 *
 * Getting started with CoSign Signature SOAP API: http://developer.arx.com/quick-start/sapi-web-services/
 */
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

// URL to the WSDL file
$wsdl = 'https://prime.cosigntrial.com:8080/sapiws/dss.asmx?WSDL';
if (file_exists('loginData.php')) {
    // The vars are defined in this file for privacy reason.
    require('loginData.php');
} else {
    // CoSign account username/email address
    $username = '';
    // CoSign account password
    $password = '';
    // CoSign account domain
    $domain = '';
}

// Timestamp configuration
$tsUrl = ''; // e.g. http://zeitstempel.dfn.de
$tsUsername = '';
$tsPassword = '';

// Options for the SoapClient instance
$clientOptions = array(
    'stream_context' => stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'cafile' => __DIR__ . '/cacert.pem',
            'peer_name' => 'prime.cosigntrial.com'
        )
    )
));

// create a HTTP writer
$writer = new SetaPDF_Core_Writer_Http('CoSign-Signed.pdf');
// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/Laboratory-Report.pdf', $writer);

// let's prepare the temporary file writer:
SetaPDF_Core_Writer_TempFile::setTempDir(realpath('_tmp/'));

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
$signer->setAllowSignatureContentLengthChange(false);
$signer->setSignatureContentLength(12000);

// set some signature properies
$signer->setLocation($_SERVER['SERVER_NAME']);
$signer->setContactInfo('+01 2345 67890123');
$signer->setReason('Testing CoSign Signature SOAP API');

// create an CoSign module instance
$module = new SetaPDF_Signer_CoSign_Module($wsdl, $username, $password, $domain, $clientOptions);
// add timestamp server data
if ($tsUrl) {
    $module->setTimestampData($tsUrl, $tsUsername, $tsPassword);
}

// sign the document with the use of the module
$signer->sign($module);
