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

class DMAPIClient {

    private $Values;
    private $List;
    private $Header;
    private $Command;
    private $Session;
    private $Errors;
    private $Username;
    private $ApiKey;
    private $Testmode;
    private $DomainCount;
    private static $instance = null;

    private function __construct($apiKey, $testmode) {
        $this->Session = false;
        $this->Testmode = $testmode;
        $this->ApiKey = $apiKey;
        $this->Reset();
    }

    public static function getInstance($params) {
        if (self::$instance == false) {
            self::$instance = new self($params["Api Key"], $params["Test Mode"]);
        }
        return self::$instance;
    }

    private function Reset() {
        $this->Command = "";
        $this->Errors = array();
        $this->Header = array();
        $this->Values = array();
        $this->List = array();
    }

    public function getValue($key,$default=false) {
        return isset($this->Values[$key]) ? $this->Values[$key] : $default;
    }

    public function getResponseList() {
        return $this->List;
    }

    public function getHeaderValue($key,$default=false) {
        return isset($this->Header[$key]) ? $this->Header[$key] : $default;
    }

    private function AddError($error) {
        $this->Errors[] = $error;
    }

    public function hasError() {
        return count($this->Errors) > 0;
    }

    public function getError() {
        return implode(";", $this->Errors);
    }

    public function getUsername() {
        return $this->Username;
    }

    public function getDomainCount() {
        return $this->DomainCount;
    }

    private function ParseResponse($response) {
        if (!$response || !is_string($response)) {
            $this->AddError("Request failed: Empty response - Please try again later");
            return false;
        }
        $responseParts = explode("\n\n", $response, 2);

        $this->Header = $this->parseKeyValueList($responseParts[0]);
        $rawBody = "";
        if (count($responseParts) > 1) {
            $rawBody = $responseParts[1];
        }
        if (!isset($this->Header["Status-Code"]) || ($this->Header["Status-Code"] != 0 && $this->Header["Status-Code"] != 1000)) {
            $this->AddError(
                    "DMAPI request '" . $this->Command . "' failed: " . $this->Header["Status-Code"]
                    . (isset($this->Header["Status-Text"]) ? " " . $this->Header["Status-Text"] : '')
                    . (isset($this->Header["Error"]) ? " " . (is_array($this->Header["Error"]) ? implode(";", $this->Header["Error"]) : $this->Header["Error"]) : '')
            );
            return false;
        }
        if (isset($this->Header["Auth-Sid"])) {
            $this->Session = $this->Header["Auth-Sid"];
        }
        if (isset($this->Header["Stats-number-of-domains"])) {
            $this->DomainCount = $this->Header["Stats-number-of-domains"];
        }

        $Body = $this->parseKeyValueList($rawBody);
        if (substr($this->Command, -4) == 'list' || $this->Command == 'dns-zone-get') {
            $this->List = $this->ParseResponseList($rawBody);
        } else {
            $this->List = array();
        }
        $this->Values = array_merge($this->Values, $Body);
        return true;
    }

    private function parseKeyValueList($data) {
        $result = array();
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $keyvalue = explode(' ', $line, 2);
            $key = rtrim($keyvalue[0], ': ');
            $value = (count($keyvalue) == 2) ? $keyvalue[1] : "";
            if (isset($result[$key])) {
                if (is_array($result[$key])) {
                    $result[$key][] = $value;
                } else {
                    $result[$key] = array($result[$key], $value);
                }
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function parseResponseList($data) {
        $result = array();
        $separator = " ";
        if (isset($this->Header['Separator']) && $this->Header['Separator'] == 'TAB' || substr($this->Command, 0, 3) === "v2/") {
            $separator = "\t";
        }
        $columnTitles = Array();
        if (isset($this->Header['Columns'])) {
            $columnTitles = explode(",", $this->Header['Columns']);
        }
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }
            $values = explode($separator, $line);
            if (count($columnTitles) > 0) {
                $columns = array();
                foreach ($values as $key => $value) {
                    $columns[$columnTitles[$key]] = $value;
                }
                $result[] = $columns;
            } else {
                $result[] = $values;
            }
        }
        return $result;
    }

    public function ExecuteAction($command, $params, $method = 'post') {
        if ($this->Session === false) {
            $loginResult = $this->Login();
            if ($this->Session === false) {
                return $loginResult;
            }
        }
        return $this->SendCommand($command, $params, $method);
    }

    private function Login() {
        $params = array(
            "api-key" => $this->ApiKey
        );
        $result = $this->SendCommand("login", $params);
        if ($this->Session !== false && empty($this->Username)) {
            $this->Username = $this->getHeaderValue('User-Login');
        }
        return $result;
    }

    private function SendCommand($command, $params, $method = 'post') {
        $this->Reset();
        $this->Command = $command;
        $this->Values = Array();
        if ($this->Testmode) {
            $host = 'dmapi.ote.joker.com';
        } else {
            $host = 'dmapi.joker.com';
        }
        $agent = 'ClientExec';

        if (!$this->Session === false) {
            $params['auth-sid'] = $this->Session;
        }

        $queryString = "";
        foreach ($params as $name => $value) {
            $queryString .= $name . "=" . urlencode($value) . "&";
        }
        if (!empty($queryString)) {
            $queryString = substr($queryString, 0, -1);
        }

        $ch = curl_init();
        $url = "https://" . $host . "/request/" . $command;
        if (substr($command, 0, 3) === "v2/" || $command === "whois") {
            $url = "https://" . $host . "/" . $command;
        }

        if (strtolower($method) == 'post') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        } elseif (!empty($queryString)) {
            $url .= "?$queryString";
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        $response = curl_exec($ch);

        if (curl_error($ch)) {
            $this->AddError("CURL Error: " . curl_errno($ch) . " - " . curl_error($ch));
        }
        curl_close($ch);

        if ($response) {
            $this->ParseResponse($response);
        }
        if (class_exists("CE_Lib")) {
            CE_Lib::log(4, 'Joker: '.$command.':'.$queryString.':'.($this->hasError() ? $this->getError() : $response));
        }
        return $response;
    }

}
