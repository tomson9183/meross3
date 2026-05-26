<?php

declare(strict_types=1);

class MerossControl extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceIP', '192.168.178.105');
        $this->RegisterPropertyString('DeviceKey', '');
        $this->RegisterPropertyInteger('PollInterval', 30);
        $this->RegisterVariableBoolean('Power', 'Steckdose', '~Switch', 10);
        $this->EnableAction('Power');
        $this->RegisterVariableFloat('Voltage', 'Spannung (V)', '', 20);
        $this->RegisterVariableFloat('Current', 'Strom (A)', '', 30);
        $this->RegisterVariableFloat('Power_W', 'Leistung (W)', '', 40);
        $this->RegisterTimer('PollTimer', 0, 'MSS_UpdateStatus($_IPS[\'TARGET\']);');
    }

    public function Destroy()
    {
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $interval = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('PollTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->UpdateStatus();
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'DeviceIP',
                    'caption' => 'IP-Adresse der Steckdose'
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'DeviceKey',
                    'caption' => 'Device Key (leer lassen)'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'PollInterval',
                    'caption' => 'Status-Intervall in Sekunden (0 = aus)',
                    'minimum' => 0,
                    'maximum' => 3600
                ]
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Einschalten',
                    'onClick' => 'MSS_TurnOn($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Ausschalten',
                    'onClick' => 'MSS_TurnOff($id);'
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Status aktualisieren',
                    'onClick' => 'MSS_UpdateStatus($id);'
                ]
            ],
            'status' => [
                ['code' => 102, 'icon' => 'Active',   'caption' => 'Verbunden'],
                ['code' => 201, 'icon' => 'Inactive', 'caption' => 'Keine Verbindung']
            ]
        ]);
    }

    public function RequestAction($ident, $value)
    {
        if ($ident === 'Power') {
            $value ? $this->TurnOn() : $this->TurnOff();
        }
    }

    public function TurnOn(): bool
    {
        $ok = $this->SendToggle(1);
        if ($ok) {
            $this->SetValue('Power', true);
        }
        return $ok;
    }

    public function TurnOff(): bool
    {
        $ok = $this->SendToggle(0);
        if ($ok) {
            $this->SetValue('Power', false);
        }
        return $ok;
    }

    public function UpdateStatus(): bool
    {
        $response = $this->SendRequest('GET', 'Appliance.System.All', []);
        if ($response === false) {
            return false;
        }
        $onoff = $response['payload']['all']['control']['toggle']['onoff'] ?? null;
        if ($onoff !== null) {
            $this->SetValue('Power', (bool)$onoff);
        }
        $elec = $response['payload']['all']['control']['electricity'] ?? null;
        if ($elec !== null) {
            $this->ApplyElectricityValues($elec);
        } else {
            $this->UpdateElectricity();
        }
        return true;
    }

    public function UpdateElectricity(): bool
    {
        $response = $this->SendRequest('GET', 'Appliance.Control.Electricity', []);
        if ($response === false) {
            return false;
        }
        $elec = $response['payload']['electricity'] ?? null;
        if ($elec !== null) {
            $this->ApplyElectricityValues($elec);
        }
        return true;
    }

    private function ApplyElectricityValues(array $elec): void
    {
        $this->SetValue('Voltage', round(($elec['voltage'] ?? 0) / 10, 1));
        $this->SetValue('Current', round(($elec['current'] ?? 0) / 1000, 3));
        $this->SetValue('Power_W', round(($elec['power']   ?? 0) / 1000, 1));
    }

    private function SendToggle(int $onoff): bool
    {
        $result = $this->SendRequest('SET', 'Appliance.Control.Toggle', [
            'toggle' => ['onoff' => $onoff, 'channel' => 0]
        ]);
        return $result !== false;
    }

    private function SendRequest(string $method, string $namespace, array $payload)
    {
        $ip  = $this->ReadPropertyString('DeviceIP');
        $key = $this->ReadPropertyString('DeviceKey');
        if (empty($ip)) {
            $this->SetStatus(201);
            return false;
        }
        $messageId = md5(uniqid((string)rand(), true));
        $timestamp = time();
        $sign      = md5($messageId . $key . $timestamp);
        $body = json_encode([
            'header' => [
                'from'           => '',
                'messageId'      => $messageId,
                'method'         => $method,
                'namespace'      => $namespace,
                'payloadVersion' => 1,
                'sign'           => $sign,
                'timestamp'      => $timestamp
            ],
            'payload' => $payload
        ]);
        $ch = curl_init("http://{$ip}/config");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $raw   = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($error || $code !== 200) {
            $this->SetStatus(201);
            $this->LogMessage("Meross Fehler: {$error} HTTP:{$code}", KL_ERROR);
            return false;
        }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SetStatus(201);
            return false;
        }
        $this->SetStatus(102);
        return $data;
    }
}
