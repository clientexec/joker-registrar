<?php

/*
 ****************************************************************************
 *                                                                          *
 * The MIT License (MIT)                                                    *
 * Copyright (c) 2020 Joker.com                                             *
 * Permission is hereby granted, free of charge, to any person obtaining a  *
 * copy of this software and associated documentation files                 *
 * (the "Software"), to deal in the Software without restriction, including *
 * without limitation the rights to use, copy, modify, merge, publish,      *
 * distribute, sublicense, and/or sell copies of the Software, and to       *
 * permit persons to whom the Software is furnished to do so, subject to    *
 * the following conditions:                                                *
 *                                                                          *
 * The above copyright notice and this permission notice shall be included  *
 * in all copies or substantial portions of the Software.                   *
 *                                                                          *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS  *
 * OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF               *
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.   *
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY     *
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,     *
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE        *
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.                   *
 *                                                                          *
 ****************************************************************************
 */

require_once 'modules/admin/models/RegistrarPlugin.php';
require_once dirname(__FILE__).'/DmapiClient.php';

/**
* @package Plugins
*/
class PluginJoker extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => true,
        'importDomains' => true,
        'importPrices' => false,
    ];

    private $recordTypes = array('A', 'AAAA', 'MX', 'CNAME', 'URL', 'FRAME', 'TXT');

    public function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array (
                                'type'          =>'hidden',
                                'description'   =>lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                                'value'         =>lang('Joker.com')
                               ),
            lang('Test Mode') => array(
                                'type'          =>'yesno',
                                'description'   =>lang('Select Yes if you wish to use Joker\'s OT&E system'),
                                'value'         =>0
                               ),
            lang('Api Key')  => array(
                                'type'          =>'text',
                                'description'   =>lang('Enter the API Key for your Joker.com account.'),
                                'value'         =>'',
                                ),
            lang('Supported Features')  => array(
                                'type'          => 'label',
                                'description'   => '* '.lang('TLD Lookup').' <br>* '.lang('Domain Registration').' <br>* '.lang('Existing Domain Importing').' <br>* '.lang('Get / Set Auto Renew Status').' <br>* '.lang('Get / Set DNS Records').' <br>* '.lang('Get / Set Nameserver Records').' <br>* '.lang('Get / Set Contact Information').' <br>* '.lang('Get / Set Registrar Lock').' <br>* '.lang('Initiate Domain Transfer').' <br>* '.lang('Automatically Renew Domain').' <br>* '.lang('Send Transfer Key'),
                                'value'         => ''
                                ),
            lang('Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                                'value'         => 'Register'
                                ),
            lang('Registered Actions') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),SendTransferKey (Send Auth Info),Cancel',
                                ),
            lang('Registered Actions For Customer') => array (
                                'type'          => 'hidden',
                                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                                'value'         => 'SendTransferKey (Send Auth Info)',
            )
        );

        return $variables;
    }

    private function getDmapiClient()
    {
        $params = array(
            'Api Key' => $this->settings->get("plugin_joker_Api Key"),
            'Test Mode' => $this->settings->get("plugin_joker_Test Mode")
        );
        return DMAPIClient::getInstance($params);
    }

    // returns array(code [,message]), where code is:
    // 0:       Domain available
    // 1:       Domain already registered
    // 2:       Registrar Error, domain extension not recognized or supported
    // 3:       Domain invalid
    // 5:       Could not contact registry to lookup domain
    public function checkDomain($params)
    {

        // the domains array in the format that CE expects to be returned.
        $domains = array();

        $tlds = array($params['tld']);

        $Joker = $this->getDmapiClient();


        if (isset($params['namesuggest'])) {
            foreach ($params['namesuggest'] as $tld) {
                if ($tld !== $params['tld']) {
                    $tlds[] = $tld;
                }
            }
        }

        $sld = $params['sld'];

        foreach ($tlds as $tld) {
            $domain = $sld.'.'.$tld;
            $reqParams = array(
                'domain' => $domain,
            );
            $Joker->ExecuteAction('domain-check', $reqParams);
            $status = 5;
            if ($Joker->hasError()) {
                $error = $Joker->getError();
                if (strpos($error, 'Domain with extension supported by Joker.com')) {
                    $status = 2;
                } else {
                    $status = 5;
                }
            } else {
                $status_text = $Joker->getValue('domain-status');
                switch ($status_text) {
                    case 'free':
                    case 'available':
                        $status = 0;
                        break;
                    case 'premium':
                        $status = 3;
                        break;
                    default:
                    case 'unavailable':
                        $status = 1;
                        break;
                }
            }
            $domains[] = array("tld"=>$tld,"domain"=>$sld,"status"=>$status);
        }
        return array("result"=>$domains);
    }

    /**
     * Initiate a domain transfer
     *
     * @param array $params
     */
    public function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar").'-'.$transferid);
        $userPackage->setCustomField('Transfer Status', $transferid);
        return "Transfer of has been initiated.";
    }

    /**
     * Register domain name
     *
     * @param array $params
     */
    public function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar").'-'.$orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }

    /**
     * Renew domain name
     *
     * @param array $params
     */
    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar").'-'.$orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    public function getTransferStatus($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);

        $reqParams = array();
        $reqParams["Proc-ID"] = $userPackage->getCustomField('Transfer Status');
        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("result-retrieve", $reqParams);
        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: " . $Joker->getError());
        }

        $status = $Joker->getValue("Completion-Status");

        if ($status == "ack") {
            $userPackage->setCustomField('Transfer Status', 'Completed');
        }

        return $status;
    }

    public function initiateTransfer($params)
    {

        $ownerHandle = $this->createOwnerContact($params);

        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $reqParams["owner-c"] = $ownerHandle;
        $reqParams["admin-c"] = $ownerHandle;
        $reqParams["tech-c"] = $ownerHandle;
        $reqParams["billing-c"] = $ownerHandle;
        $reqParams["transfer-auth-id"] = $params['eppCode'];
        $reqParams["autorenew"] = '0';

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("domain-transfer-in-reseller", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }

        return $Joker->getHeaderValue("Proc-ID");
    }

    public function renewDomain($params)
    {

        $Joker = $this->getDmapiClient();

        $Joker->ExecuteAction("query-profile", array());
        if ($Joker->getValue('balance') <= 0) {
            throw new CE_Exception("Joker.com Error: Account balance is too low");
        }


        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $reqParams["period"] = $params['NumYears'] * 12;
        $reqParams["privacy"] = "keep";
        $Joker->ExecuteAction("domain-renew", $reqParams);


        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: Registrant: " . $Joker->getError());
        }
        return $Joker->getHeaderValue("Proc-ID");
    }

    public function createOwnerContact($params)
    {

        $reqParams = array();
        $reqParams["tld"] = $params['tld'];
        //$reqParams["fax"] = "";
        $reqParams["phone"] = $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']);
        $reqParams["country"] = $params['RegistrantCountry'];
        $reqParams["postal-code"] = $params['RegistrantPostalCode'];
        $reqParams["state"] = $params['RegistrantStateProvince'];
        $reqParams["city"] = $params['RegistrantCity'];
        $reqParams["email"] = $params['RegistrantEmailAddress'];
        $reqParams["address-1"] = $params['RegistrantAddress1'];
        $reqParams["address-2"] = $params['RegistrantAddress2'];
        $reqParams["name"] = $params['RegistrantFirstName'] . ' ' . $params['RegistrantLastName'];
        $reqParams["organization"] = $params['RegistrantOrganizationName'];

        if ($params['tld'] == 'fi') {
            unset($reqParams["name"]);
            $reqParams["fname"] = $params['RegistrantFirstName'];
            $reqParams["lname"] = $params['RegistrantLastName'];
        } elseif ($params['tld'] == 'eu') {
            $reqParams["lang"] = "EN";
        }

        /*
        if (is_array($params['ExtendedAttributes'])) {
            foreach ($params['ExtendedAttributes'] as $name => $value) {
                $reqParams[$name] = $value;
            }
        }
        */

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("v2/contact/create", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: Registrant: " . $Joker->getError());
        }
        sleep(1);
        return $Joker->getValue('handle');
    }

    public function registerDomain($params)
    {

        $ownerHandle = $this->createOwnerContact($params);

        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $reqParams["period"] = $params['NumYears'] * 12;
        $reqParams["status"] = "production";
        $reqParams["owner-c"] = $ownerHandle;

        if (!isset($params['NS1'])) {
            $reqParams["ns-list"] = "a.ns.joker.com:b.ns.joker.com:c.ns.joker.com";
        } else {
            $nslist = array();
            for ($i = 1; $i <= 5; $i++) {
                if (isset($params["NS$i"]) && !empty($params["NS$i"]['hostname'])) {
                    $nslist[] = $params["NS$i"]['hostname'];
                }
            }
            $reqParams["ns-list"] = implode(':', $nslist);
        }

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("domain-register", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }
        return $Joker->getHeaderValue("Proc-ID");
    }

    private function validatePhone($phone, $country)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);

        if ($phone == '') {
            return $phone;
        }

        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return $phone;
        }

        // check if code is already there
        $code = $row['phone_code'];
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);
        if ($phone[0] == '+') {
            return $phone;
        }

        // if not, prepend it
        return "+$code.$phone";
    }

    public function getContactInformation($params)
    {
        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $reqParams["internal"] = 1;

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('query-whois', $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }

        $info = array();

        $names = explode(" ", $Joker->getValue("domain.name"));
        $lastname = array_pop($names);
        $firstname = implode(" ", $names);

        $info["Registrant"] = array(
            'OrganizationName'  => array($this->user->lang('Organization'), $Joker->getValue("domain.organization", '')),
            'FirstName'         => array($this->user->lang('First Name'), $firstname),
            'LastName'          => array($this->user->lang('Last Name'), $lastname),
            'Address1'          => array($this->user->lang('Address').' 1', $Joker->getValue("domain.address-1")),
            'Address2'          => array($this->user->lang('Address').' 2', $Joker->getValue("domain.address-2", '')),
            'City'              => array($this->user->lang('City'), $Joker->getValue("domain.city")),
            'StateProvince'         => array($this->user->lang('Province').'/'.$this->user->lang('State'), $Joker->getValue("domain.state", '')),
            'Country'           => array($this->user->lang('Country'), $Joker->getValue("domain.country")),
            'PostalCode'        => array($this->user->lang('Postal Code'), $Joker->getValue("domain.postal-code")),
            'EmailAddress'      => array($this->user->lang('E-mail'), $Joker->getValue("domain.email")),
            'Phone'             => array($this->user->lang('Phone'), $Joker->getValue("domain.phone")),
            'Fax'               => array($this->user->lang('Fax'), $Joker->getValue("domain.fax", '')),
        );

        // Don't allow to change admin, tech and billing contact for now
        /*
        $contacts = array(
            "Admin" => $Joker->getValue("domain.admin-c"),
            "Tech" => $Joker->getValue("domain.tech-c"),
            "AuxBilling" => $Joker->getValue("domain.billing-c")
        );

        foreach($contacts as $type => $handle) {
            $reqParams = Array();
            $reqParams["contact"] = $handle;
            $Joker->ExecuteAction('query-whois', $reqParams);

            if ($Joker->hasError()) {
                //throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
                continue;
            }

            $names = explode(" ", $Joker->getValue("contact.name"));
            $lastname = array_pop($names);
            $firstname = implode(" ", $names);
            $info[$type] = array(
                'OrganizationName'  => array($this->user->lang('Organization'), $Joker->getValue("contact.organization",'')),
                'JobTitle'          => array($this->user->lang('Job Title'), ''),
                'FirstName'         => array($this->user->lang('First Name'), $firstname),
                'LastName'          => array($this->user->lang('Last Name'), $lastname),
                'Address1'          => array($this->user->lang('Address').' 1', $Joker->getValue("contact.address-1")),
                'Address2'          => array($this->user->lang('Address').' 2', $Joker->getValue("contact.address-2",'')),
                'City'              => array($this->user->lang('City'), $Joker->getValue("contact.city")),
                'StateProvince'         => array($this->user->lang('Province').'/'.$this->user->lang('State'), $Joker->getValue("contact.state",'')),
                'Country'           => array($this->user->lang('Country'), $Joker->getValue("contact.country")),
                'PostalCode'        => array($this->user->lang('Postal Code'), $Joker->getValue("contact.postal-code")),
                'EmailAddress'      => array($this->user->lang('E-mail'), $Joker->getValue("contact.email")),
                'Phone'             => array($this->user->lang('Phone'), $Joker->getValue("contact.phone")),
                'Fax'               => array($this->user->lang('Fax'), $Joker->getValue("contact.fax",''))
            );
        }
        */

        return $info;
    }

    public function setContactInformation($params)
    {
        if ($params['type']!='Registrant') {
            throw new CE_Exception("Joker.com API Error: Contact type '{$params['type']}' currently not supported");
        }

        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $reqParams["fax"] = $params["Registrant_Fax"];
        $reqParams["phone"] = $this->validatePhone($params['Registrant_Phone'], $params["Registrant_Country"]);
        $reqParams["country"] = $params["Registrant_Country"];
        $reqParams["postal-code"] = $params["Registrant_PostalCode"];
        $reqParams["state"] = $params["Registrant_StateProv"];
        $reqParams["city"] = $params["Registrant_City"];
        $reqParams["email"] = $params["Registrant_EmailAddress"];
        $reqParams["address-1"] = $params["Registrant_Address1"];
        $reqParams["address-2"] = $params["Registrant_Address2"];
        $reqParams["name"] = $params["Registrant_FirstName"] . ' ' . $params["Registrant_LastName"];
        $reqParams["organization"] = $params["Registrant_OrganizationName"];

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("domain-owner-change", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ". $Joker->getError());
        }

        return $this->user->lang('Contact Information updated successfully.');
    }

    public function getNameServers($params)
    {
        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('query-whois', $reqParams);
        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }

        $info = array();

        $info['hasDefault'] = true;
        $info['usesDefault'] = false;


        $nameservers = $Joker->getValue('domain.nservers.nserver.handle');
        foreach ($nameservers as $nameserver) {
            if (in_array($nameserver, ['a.ns.joker.com','a.ns.nrw.net'])) {
                $info['usesDefault'] = true;
            }
            if (!empty($nameserver)) {
                $info[] = $nameserver;
            }
        }
        return $info;
    }

    public function setNameServers($params)
    {
        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];

        if ($params['default'] == true) {
            $reqParams["ns-list"] = "a.ns.joker.com:b.ns.joker.com:c.ns.joker.com";
        } else {
            $reqParams["ns-list"] = implode(":", $params['ns']);
        }

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('domain-modify', $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }
    }

    public function getDNS($params)
    {
        $reqParams = array();
        $reqParams["pattern"] = $params['sld'].'.'.$params['tld'];


        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('dns-zone-list', $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }

        $resultList = $Joker->getResponseList();

        $hostRecords = array();
        $useDefault = false;

        if (count($resultList) > 0) {
            $reqParams = array();
            $reqParams["domain"] = $params['sld'].'.'.$params['tld'];

            $Joker = $this->getDmapiClient();
            $Joker->ExecuteAction("dns-zone-get", $reqParams);

            if ($Joker->hasError()) {
                throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
            } else {
                $dnsrecords = $Joker->getResponseList();
                foreach ($dnsrecords as $record) {
                    if (count($record) > 2 && ($record[1] !== "NS" || $record[0] !== "@")) {
                        if ($record[1] == "TXT") {
                            $hostRecords[] = array(
                                "hostname" => $record[0],
                                "type" => $record[1],
                                "priority" => $record[2],
                                "address" => substr(implode(" ", array_slice($record, 3, -1)), 2, -2)
                            );
                        } elseif ($record[1] !== "MAILFW") {
                            $hostRecords[] = array(
                                "hostname" => $record[0],
                                "type" => $record[1],
                                "address" => $record[3],
                                "priority" => $record[2],
                            );
                        }
                    }
                }
                $useDefault = true;
            }
        }
        return array('records' => $hostRecords, 'types' => $this->recordTypes, 'default' => $useDefault);
    }

    public function setDNS($params)
    {
        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("dns-zone-get", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }

        $olddnsrecords = $Joker->getResponseList();

        $dnsrecords = array();

        foreach ($olddnsrecords as $record) {
            if ((count($record) > 2 && $record[1] == "MAILFW") || substr($record[0], 0, 7) == '$dyndns') {
                $dnsrecords[] = implode(" ", $record);
            }
        }

        foreach ($params['records'] as $record) {
            if ($record && $record["address"] && $record["type"] != 'MXE') {
                $dnsrecords[] = (empty($record["hostname"]) ? '@' : $record["hostname"]) . " {$record["type"]} " . ($record["type"] == "MX" && isset($record["priority"]) ? $record["priority"] : 0) . " " . ($record["type"] == "TXT" ? '"' : '') . $record["address"] . ($record["type"] == "TXT" ? '"' : '') . " 86400 0 0";
            }
        }

        $reqParams["zone"] = implode("\n", $dnsrecords);
        $Joker->ExecuteAction("dns-zone-put", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }
    }

    public function getGeneralInfo($params)
    {

        $domain = $params['sld'].'.'.$params['tld'];
        $reqParams = array();
        $reqParams["pattern"] = $domain;
        $reqParams["showstatus"] = 1;


        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('query-domain-list', $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
        }

        $data = array();

        $resultList = $Joker->getResponseList();

        if (count($resultList) > 0) {
            $status = explode(",", $resultList[0]['domain_status']);
            $expDate = new DateTime($resultList[0]['expiration_date'], new DateTimeZone('UTC'));
            $now = new DateTime(null, new DateTimeZone('UTC'));
            $data['domain'] = $resultList[0]['domain'];
            $data['expiration'] = $resultList[0]['expiration_date'];
            $data['registrationstatus'] = 'Registered';
            $data['purchasestatus'] = 'N/A';
            $data['is_registered'] = ($expDate > $now);
            $data['is_expired'] = ($expDate <= $now);
            $data['autorenew'] = in_array('autorenew', $status)?1:0;
        } else {
            $reqParams = array();
            $reqParams["rtype"] = "domain-r*";
            $reqParams["objid"] = $domain;
            $reqParams["showall"] = 1;
            $reqParams["pending"] = 1;
            $reqParams["limit"] = 1;
            $reqParams["period"] = 1;

            $Joker->ExecuteAction('result-list', $reqParams);

            if ($Joker->hasError()) {
                throw new CE_Exception("Joker.com API Error: ".$Joker->getError());
            } elseif ($Joker->getHeaderValue('Row-Count') > 0) {
                $resultList = $Joker->getResponseList();
                $status = $resultList[0][5];
                if ($status == "nack") {
                    $data['domain'] = $domain;
                    $data['registrationstatus'] = 'Failed';
                    $data['purchasestatus'] = 'Canceled';
                }
            } else {
                throw new CE_Exception("Joker.com API Error: domain/order not found");
            }
        }
        return $data;
    }

    public function fetchDomains($params)
    {
        $pageSize = 25;
        $from = $params['next'];
        $to = $params['next']+$pageSize-1;


        $reqParams =  array(
            'from' => $from,
            'to'  => $to

        );
        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('query-domain-list', $reqParams);

        $resultList = $Joker->getResponseList();


        $domainsList = array();

        foreach ($resultList as $result) {
            $splitDomain = DomainNameGateway::splitDomain($result['domain']);
            $domainsList[] = [
                'sld' => $splitDomain[0],
                'tld' => $splitDomain[1],
                'exp' => $result['expiration_date']
            ];
        }

        $end = $from + count($resultList)-1;


        $metaData = array();
        $metaData['total'] = $Joker->getDomainCount();
        $metaData['next'] = $to != $end?null:$to+1;
        $metaData['start'] = $from;
        $metaData['end'] = $end;
        $metaData['numPerPage'] = $pageSize;
        return array($domainsList,$metaData);
    }

    public function setAutorenew($params)
    {
        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $reqParams["pname"] = "autorenew";
        $reqParams["pvalue"] = $params['autorenew']?1:0;
        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('domain-set-property', $reqParams);
        if ($Joker->hasError()) {
            throw new CE_Exception('Joker.com Api Error: '.$Joker->getError());
        }

        return "Domain updated successfully";
    }

    public function getRegistrarLock($params)
    {

        $reqParams = array();
        $reqParams["pattern"] = $params['sld'].'.'.$params['tld'];
        $reqParams["showstatus"] = 1;

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('query-domain-list', $reqParams);

        $resultList = $Joker->getResponseList();

        if (count($resultList) > 0) {
            $status = explode(",", $resultList[0]['domain_status']);
            if (in_array('lock', $status)) {
                $lockstatus = 1;
            } else {
                $lockstatus = 0;
            }
            return $lockstatus;
        }
        throw new CE_Exception('Joker.com Api Error: Domain not found');
    }

    public function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

    public function setRegistrarLock($params)
    {
        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];
        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction('domain-'.($params['lock']?'lock':'unlock'), $reqParams);
        if ($Joker->hasError()) {
            throw new CE_Exception('Joker.com Api Error: '.$Joker->getError());
        }
    }

    public function doSendTransferKey($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->sendTransferKey($this->buildRegisterParams($userPackage, $params));
        return 'Successfully sent auth info for ' . $userPackage->getCustomField('Domain Name');
    }

    public function sendTransferKey($params)
    {

        $reqParams = array();
        $reqParams["domain"] = $params['sld'].'.'.$params['tld'];

        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("domain-transfer-get-auth-id", $reqParams);

        if ($Joker->hasError()) {
            throw new CE_Exception('Joker.com Api Error: '.$Joker->getError());
        }
    }

    public function getTLDsAndPrices($params)
    {
        $Joker = $this->getDmapiClient();
        $Joker->ExecuteAction("v2/query-price-list");
        if ($Joker->hasError()) {
            throw new CE_Exception('Joker.com Api Error: '.$Joker->getError());
        }

        $priceList = $Joker->getResponseList();
        $tlds = array();

        foreach ($priceList as $data) {
            // do not support IDN domains currently
            if (substr($data['tld'], 0, 4) == 'xn--') {
                continue;
            }

            if (in_array($data['type'], array("domain","domain_promo"))) {
                if (!isset($tlds[$data['tld']])) {
                    $tlds[$data['tld']] = array('pricing' => array());
                }
                switch ($data['operation']) {
                    case "create":
                        $tlds[$data['tld']]['pricing']['register'] = $data['price-1y'];
                        break;
                    case "transfer":
                        $tlds[$data['tld']]['pricing']['transfer'] = $data['price-1y'];
                        break;
                    case "renew":
                        $tlds[$data['tld']]['pricing']['renew'] = $data['price-1y'];
                        break;
                }
            }
        }

        return $tlds;
    }
}
