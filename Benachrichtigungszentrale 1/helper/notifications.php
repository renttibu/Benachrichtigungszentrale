<?php

/** @noinspection PhpUnused */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

trait BZ1_notifications
{
    /**
     * Sends a notification.
     *
     * @param string $PushTitle
     * @param string $PushText
     * @param string $EmailSubject
     * @param string $EmailText
     * @param string $SMSText
     * @param int $MessageType
     * 0    = Notification
     * 1    = Acknowledgement
     * 2    = Alert
     * 3    = Sabotage
     * 4    = Battery
     */
    public function SendNotification(string $PushTitle, string $PushText, string $EmailSubject, string $EmailText, string $SMSText, int $MessageType): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->SendPushNotification($PushTitle, $PushText, $MessageType);
        $this->SendEMailNotification($EmailSubject, $EmailText, $MessageType);
        $this->SendSMSNotification($SMSText, $MessageType);
    }

    /**
     * Repeats the last alarm message, if user hasn't confirmed the message in time.
     */
    public function RepeatAlarmNotification(): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $actualAttempts = $this->ReadAttributeInteger('AlarmNotificationAttempt');
        $actualAttempts++;
        $definedAttempts = $this->ReadPropertyInteger('AlarmNotificationAttempts');
        if ($actualAttempts <= $definedAttempts) {
            $title = $this->ReadAttributeString('AlarmNotificationTitle');
            $text = $this->ReadAttributeString('AlarmNotificationText');
            if (!empty($title) && !empty($text)) {
                $this->SendPushNotification($title, $text, 2);
            }
            $this->WriteAttributeInteger('AlarmNotificationAttempt', $actualAttempts);
        }
        if ($actualAttempts == $definedAttempts) {
            $this->ConfirmAlarmNotification();
        }
    }

    /**
     * Resets the alarm notification.
     */
    public function ConfirmAlarmNotification(): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $this->DeactivateTimers();
        $this->ResetAttributes();
    }

    /**
     * Sends a push notification.
     *
     * @param string $Title
     * @param string $Text
     * @param int $MessageType
     * 0    = Notification
     * 1    = Acknowledgement
     * 2    = Alert
     * 3    = Sabotage
     * 4    = Battery
     */
    public function SendPushNotification(string $Title, string $Text, int $MessageType): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        if (!$this->ReadPropertyBoolean('UsePushNotification')) {
            return;
        }
        $Title = substr($Title, 0, 32);
        $webFronts = json_decode($this->ReadPropertyString('WebFronts'));
        if (!empty($webFronts)) {
            foreach ($webFronts as $webFront) {
                if ($webFront->Use) {
                    if ($webFront->ID != 0 && IPS_ObjectExists($webFront->ID)) {
                        $notification = false;
                        $sound = '';
                        $target = 0;
                        switch ($MessageType) {
                            case 0:
                                if ($webFront->Notification) {
                                    $notification = true;
                                    $sound = $webFront->NotificationSound;
                                }
                                break;

                            case 1:
                                if ($webFront->Acknowledgement) {
                                    $notification = true;
                                    $sound = $webFront->AcknowledgementSound;
                                }
                                break;

                            case 2:
                                if ($webFront->Alerting) {
                                    $notification = true;
                                    $sound = $webFront->AlertingSound;
                                    //Alerting confirmation
                                    if ($this->ReadPropertyBoolean('ConfirmAlarmMessage')) {
                                        $this->WriteAttributeString('AlarmNotificationTitle', $Title);
                                        $this->WriteAttributeString('AlarmNotificationText', $Text);
                                        $this->WriteAttributeInteger('AlarmNotificationAttempt', 1);
                                        $target = $this->GetIDForIdent('ConfirmAlarmNotification');
                                        //Set timer to next interval
                                        $duration = $this->ReadPropertyInteger('ConfirmationPeriod');
                                        $this->SetTimerInterval('RepeatAlarmNotification', $duration * 1000);
                                    }
                                }
                                break;

                            case 3:
                                if ($webFront->Sabotage) {
                                    $notification = true;
                                    $sound = $webFront->SabotageSound;
                                }
                                break;

                            case 4:
                                if ($webFront->Battery) {
                                    $notification = true;
                                    $sound = $webFront->BatterySound;
                                }
                                break;

                        }
                        if ($notification) {
                            @WFC_PushNotification($webFront->ID, $Title, $Text, $sound, $target);
                            $this->SendDebug(__FUNCTION__, $webFront->ID . ', ' . $Title . ', ' . $Text . ', ' . $MessageType, 0);
                        }
                    }
                }
            }
        }
    }

    /**
     * Sends an email notification.
     *
     * @param string $Subject
     * @param string $Text
     * @param int $MessageType
     * 0 = Notification
     * 1 = Acknowledgement
     * 2 = Alert
     * 3 = Sabotage
     * 4 = Battery
     */
    public function SendEMailNotification(string $Subject, string $Text, int $MessageType): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        if (!$this->ReadPropertyBoolean('UseSMTPNotification')) {
            return;
        }
        $recipients = json_decode($this->ReadPropertyString('SMTPRecipients'));
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                if ($recipient->Use) {
                    $id = $recipient->ID;
                    if ($id != 0 && @IPS_ObjectExists($id)) {
                        $address = $recipient->Address;
                        if (!empty($address) && strlen($address) > 3) {
                            $notification = false;
                            switch ($MessageType) {
                                case 0:
                                    if ($recipient->Notification) {
                                        $notification = true;
                                    }
                                    break;

                                case 1:
                                    if ($recipient->Acknowledgement) {
                                        $notification = true;
                                    }
                                    break;

                                case 2:
                                    if ($recipient->Alerting) {
                                        $notification = true;
                                    }
                                    break;

                                case 3:
                                    if ($recipient->Sabotage) {
                                        $notification = true;
                                    }
                                    break;

                                case 4:
                                    if ($recipient->Battery) {
                                        $notification = true;
                                    }
                                    break;

                            }
                            if ($notification) {
                                @SMTP_SendMailEx($id, $address, $Subject, $Text);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Sends a sms notification.
     *
     * @param string $Text
     * @param int $MessageType
     * 0    = Notification
     * 1    = Acknowledgement
     * 2    = Alert
     * 3    = Sabotage
     * 4    = Battery
     */
    public function SendSMSNotification(string $Text, int $MessageType): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        //Nexxt Mobile
        if ($this->ReadPropertyBoolean('UseNexxtMobile')) {
            $recipients = json_decode($this->ReadPropertyString('NexxtMobileRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        $phoneNumber = $recipient->PhoneNumber;
                        if (!empty($phoneNumber) && strlen($phoneNumber) > 3) {
                            $token = $this->ReadPropertyString('NexxtMobileToken');
                            if (!empty($token)) {
                                $token = rawurlencode($token);
                                $originator = $this->ReadPropertyString('NexxtMobileSenderPhoneNumber');
                                if (!empty($originator) && strlen($originator) > 3) {
                                    $originator = rawurlencode($originator);
                                    $notification = false;
                                    switch ($MessageType) {
                                        case 0:
                                            if ($recipient->Notification) {
                                                $notification = true;
                                            }
                                            break;

                                        case 1:
                                            if ($recipient->Acknowledgement) {
                                                $notification = true;
                                            }
                                            break;

                                        case 2:
                                            if ($recipient->Alerting) {
                                                $notification = true;
                                            }
                                            break;

                                        case 3:
                                            if ($recipient->Sabotage) {
                                                $notification = true;
                                            }
                                            break;

                                        case 4:
                                            if ($recipient->Battery) {
                                                $notification = true;
                                            }
                                            break;

                                    }
                                    if ($notification) {
                                        $messageText = rawurlencode(substr($Text, 0, 360));
                                        $endpoint = 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=sms&originator=' . $originator . '&recipient=' . $phoneNumber . '&text=' . $messageText;
                                        $timeout = $this->ReadPropertyInteger('NexxtMobileTimeout');
                                        $ch = curl_init();
                                        curl_setopt_array($ch, [
                                            CURLOPT_URL            => $endpoint,
                                            CURLOPT_RETURNTRANSFER => true,
                                            CURLOPT_FAILONERROR    => true,
                                            CURLOPT_CONNECTTIMEOUT => $timeout,
                                            CURLOPT_TIMEOUT        => 60]);
                                        $response = curl_exec($ch);
                                        $this->SendDebug(__FUNCTION__, 'NexxtMobile Response: ' . $response, 0);
                                        if (!curl_errno($ch)) {
                                            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                                                case $http_code >= 200 && $http_code < 300:
                                                    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                                                    $header = substr($response, 0, $header_size);
                                                    $this->SendDebug(__FUNCTION__, 'Header: ' . $header, 0);
                                                    $response = json_decode($header, true);
                                                    if (empty($response)) {
                                                        $this->SendDebug(__FUNCTION__, 'Nexxt Mobile, keine Rückantwort erhalten!', 0);
                                                    } else {
                                                        if (!array_key_exists('isError', $response)) {
                                                            $this->SendDebug(__FUNCTION__, 'NexxtMobile, das Feld isError ist nicht enthalten!', 0);
                                                        } else {
                                                            $isError = $response['isError'];
                                                            if ($isError) {
                                                                $this->SendDebug(__FUNCTION__, 'NexxtMobile, Fehler als Rückmeldung erhalten!', 0);
                                                            }
                                                        }
                                                    }
                                                    break;

                                                default:
                                                    $this->SendDebug(__FUNCTION__, 'NexxtMobile HTTP Code: ' . $http_code, 0);
                                            }
                                        } else {
                                            $error_msg = curl_error($ch);
                                            $this->SendDebug(__FUNCTION__, 'NexxtMobile, an error has occurred: ' . json_encode($error_msg), 0);
                                        }
                                        curl_close($ch);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        //Sipgate
        if ($this->ReadPropertyBoolean('UseSipgate')) {
            $userName = $this->ReadPropertyString('SipgateUser');
            $password = $this->ReadPropertyString('SipgatePassword');
            if (empty($userName) || empty($password)) {
                return;
            }
            $recipients = json_decode($this->ReadPropertyString('SipgateRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        $phoneNumber = $recipient->PhoneNumber;
                        if (!empty($phoneNumber) && strlen($phoneNumber) > 3) {
                            $notification = false;
                            switch ($MessageType) {
                                case 0:
                                    if ($recipient->Notification) {
                                        $notification = true;
                                    }
                                    break;

                                case 1:
                                    if ($recipient->Acknowledgement) {
                                        $notification = true;
                                    }
                                    break;

                                case 2:
                                    if ($recipient->Alerting) {
                                        $notification = true;
                                    }
                                    break;

                                case 3:
                                    if ($recipient->Sabotage) {
                                        $notification = true;
                                    }
                                    break;

                                case 4:
                                    if ($recipient->Battery) {
                                        $notification = true;
                                    }
                                    break;

                            }
                            if ($notification) {
                                $endpoint = 'https://api.sipgate.com/v2/sessions/sms';
                                $postfields = json_encode(['smsId' => 's0', 'recipient' => $phoneNumber, 'message' => $Text]);
                                $userPassword = $userName . ':' . $password;
                                $timeout = $this->ReadPropertyInteger('SipgateTimeout');
                                $ch = curl_init();
                                curl_setopt_array($ch, [
                                    CURLOPT_URL            => $endpoint,
                                    CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json'],
                                    CURLOPT_USERPWD        => $userPassword,
                                    CURLOPT_POST           => true,
                                    CURLOPT_POSTFIELDS     => $postfields,
                                    CURLOPT_RETURNTRANSFER => true,
                                    CURLOPT_FAILONERROR    => true,
                                    CURLOPT_CONNECTTIMEOUT => $timeout,
                                    CURLOPT_TIMEOUT        => 60]);
                                $response = curl_exec($ch);
                                $responseData = true;
                                if (empty($response)) {
                                    $response = 'No response received!';
                                    $responseData = false;
                                }
                                $this->SendDebug(__FUNCTION__, 'Sipgate Response: ' . $response, 0);
                                if (!curl_errno($ch)) {
                                    switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                                        case $http_code >= 200 && $http_code < 300:
                                            if ($responseData) {
                                                $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                                                $header = substr($response, 0, $header_size);
                                                $body = substr($response, $header_size);
                                                $this->SendDebug(__FUNCTION__, 'Header: ' . $header, 0);
                                                $this->SendDebug(__FUNCTION__, 'Body: ' . $body, 0);
                                            }
                                            break;

                                        default:
                                            $this->SendDebug(__FUNCTION__, 'HTTP Code: ' . $http_code, 0);
                                    }
                                } else {
                                    $error_msg = curl_error($ch);
                                    $this->SendDebug(__FUNCTION__, 'An error has occurred: ' . json_encode($error_msg), 0);
                                }
                                curl_close($ch);
                            }
                        }
                    }
                }
            }
        }
    }
}