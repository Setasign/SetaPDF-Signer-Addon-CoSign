<?php
/**
 * This file is part of the demo pacakge of the SetaPDF-Signer Component
 *
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 * @version    $Id$
 */

/**
 * CoSign class
 *
 * This class implements a signature module for the SetaPDF-Signer component by wrapping
 * the CoSign Signature SOAP API.
 *
 * @link http://developer.arx.com/quick-start/sapi-web-services/
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (https://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */
class SetaPDF_Signer_CoSign_Module implements SetaPDF_Signer_Signature_Module_ModuleInterface
{
    /**
     * The WSDL endpoint of the CoSign SOAP webservice.
     *
     * @var string
     */
    protected $_wsdl;

    /**
     * The CoSign username (e.g. the one you are using on https://webagentdev.arx.com/)
     *
     * @var string
     */
    protected $_username;

    /**
     * The CoSign password (e.g. the one you are using on https://webagentdev.arx.com/)
     *
     * @var string
     */
    protected $_password;

    /**
     * CoSign account domain
     *
     * @var string
     */
    protected $_domain;

    /**
     * Client options that will be passed to the SoapClient constructor
     *
     * @var array
     */
    protected $_clientOptions = array();

    /**
     * Timestamp data
     *
     * @var array
     */
    protected $_timestampData;

    /**
     * The digest algorithm to use when signing
     *
     * @var string
     */
    protected $_digest = SetaPDF_Signer_Digest::SHA_256;

    /**
     * The constructor.
     *
     * @param string $wsdl
     * @param string $username
     * @param string $password
     * @param string $domain
     * @param array $clientOptions
     */
    public function __construct($wsdl, $username, $password, $domain, array $clientOptions = array())
    {
        $this->_wsdl = $wsdl;
        $this->_username = $username;
        $this->_password = $password;
        $this->_domain = $domain;
        $this->_clientOptions = $clientOptions;
    }

    /**
     * Set the digest algorithm to use when signing.
     *
     * Possible values are defined in {@link SetaPDF_Signer_Digest} but are limited by the CoSign API to
     * the SetaPDF_Signer_Digest::SHA_* constants.
     *
     * @see SetaPDF_Signer_Digest
     * @param string $digest
     */
    public function setDigest($digest)
    {
        $this->_digest = $digest;
    }

    /**
     * Get the digest algorithm.
     *
     * @return string
     */
    public function getDigest()
    {
        return $this->_digest;
    }

    /**
     * Set the timestamp data.
     *
     * These data will be passed to the ConfigurationValues entry.
     *
     * @param string $url
     * @param string $username
     * @param string $password
     */
    public function setTimestampData($url, $username = '', $password = '')
    {
        $this->_timestampData = array(
            'url' => $url,
            'username' => $username,
            'password' => $password
        );
    }

    /**
     * Create the signature.
     *
     * @param SetaPDF_Core_Reader_FilePath|string $tmpPath
     * @return mixed
     * @throws SetaPDF_Signer_Exception
     */
    public function createSignature($tmpPath)
    {
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            throw new InvalidArgumentException('Signature template file cannot be read.');
        }

        $signaturType = 'urn:ietf:rfc:3369';

        $flags = 0x00000400 // The value for buffer signing is the hash itself and not the data.
            // seems not to be implemented/compatible with hash signing:
            // | 0x00001000 // AR_SAPI_SIG_PDF_REVOCATION
            // | 0x00002000 // AR_SAPI_SIG_CAdES_REVOCATION - Currently NOT supported.
        ;

        switch ($this->getDigest()) {
            case SetaPDF_Signer_Digest::SHA_1:
                $flags |= 0x00100000;
                break;
            case SetaPDF_Signer_Digest::SHA_256:
                $flags |= 0x00004000;
                break;
            case SetaPDF_Signer_Digest::SHA_384:
                $flags |= 0x00008000;
                break;
            case SetaPDF_Signer_Digest::SHA_512:
                $flags |= 0x00010000;
                break;
            default:
                throw new BadMethodCallException('The used digest method is not supported by the CoSign API.');
        }

        $req = array(
            'SignRequest'=> array(
                'OptionalInputs' => array(
                    'ClaimedIdentity' => array(
                        'Name' => array(
                            '_' => $this->_username,
                            'NameQualifier' => $this->_domain
                        ),
                        'SupportingInfo' => array(
                            'LogonPassword' => $this->_password
                        )
                    ),
                    'SignatureType' => $signaturType,
                    'Flags' => $flags,
                    'ConfigurationValues' => array(
                        'ConfValue' => array(),
                    )
                ),
                'InputDocuments' => array(
                    'Document' => array(
                        'Base64Data' => array(
                            '_' => hash_file($this->getDigest(), $tmpPath, true),
                            'MimeType' => 'application/octet-string'
                        )
                    )
                ),
            )
        );

        if (isset($this->_timestampData)) {
            $confValue =& $req['SignRequest']['OptionalInputs']['ConfigurationValues']['ConfValue'];
            $confValue[] = array(
                'ConfValueID' => 'UseTimestamp',
                'IntegerValue' => 1
            );

            $confValue[] = array(
                'ConfValueID' => 'TimestampURL',
                'StringValue' => $this->_timestampData['url']
            );

            $confValue[] = array(
                'ConfValueID' => 'TimestampUser',
                'StringValue' => $this->_timestampData['username']
            );

            $confValue[] = array(
                'ConfValueID' => 'TimestampPWD',
                'StringValue' => $this->_timestampData['password']
            );
        }

        $client = new SoapClient($this->_wsdl, array_merge($this->_clientOptions, array('trace' => true)));
        $result = $client->DssSign($req);

        if ($result->DssSignResult->Result->ResultMajor != "urn:oasis:names:tc:dss:1.0:resultmajor:Success") {
            throw new SetaPDF_Signer_Exception(sprintf('CoSign webservice returned an error: %s',
                $result->DssSignResult->Result->ResultMessage->_
            ));
        }

        return $result->DssSignResult->OptionalOutputs->DocumentWithSignature->Document->Base64Data->_;
    }
}