<?php

// Declare
declare(strict_types=1);

trait BENA_notifications
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
        $this->SendPushNotification($PushTitle, $PushText, $MessageType);
        $this->SendEMailNotification($EmailSubject, $EmailText, $MessageType);
        $this->SendSMSNotification($SMSText, $MessageType);
    }

    /**
     * Repeats the last alarm message, if user hasn't confirmed the message in time.
     */
    public function RepeatAlarmNotification(): void
    {
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
        // Deactivate timers
        $this->DeactivateTimers();

        // Reset attributes
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
                                    // Alerting confirmation
                                    if ($this->ReadPropertyBoolean('ConfirmAlarmNotification')) {
                                        $this->WriteAttributeString('AlarmNotificationTitle', $Title);
                                        $this->WriteAttributeString('AlarmNotificationText', $Text);
                                        $this->WriteAttributeInteger('AlarmNotificationAttempt', 1);
                                        $target = $this->GetIDForIdent('ConfirmAlarmNotification');
                                        // Set timer to next interval
                                        $duration = $this->ReadPropertyInteger('RepeatAlarmNotificationDurationTime');
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
        $recipients = json_decode($this->ReadPropertyString('MailRecipients'));
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
        $recipients = json_decode($this->ReadPropertyString('SMSRecipients'));
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                if ($recipient->Use) {
                    $phoneNumber = $recipient->PhoneNumber;
                    if (!empty($phoneNumber) && strlen($phoneNumber) > 3) {
                        $token = $this->ReadPropertyString('SMSToken');
                        if (!empty($token)) {
                            $token = rawurlencode($token);
                            $originator = $this->ReadPropertyString('SMSOriginator');
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
                                    // Send sms via Nexxt Mobile service
                                    $messageText = rawurlencode(substr($Text, 0, 360));
                                    $ch = curl_init();
                                    curl_setopt($ch, CURLOPT_URL, 'https://api.nexxtmobile.de/?mode=user&token=' . $token . '&function=sms&originator=' . $originator . '&recipient=' . $phoneNumber . '&text=' . $messageText);
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                                    curl_exec($ch);
                                    if (curl_errno($ch)) {
                                        IPS_LogMessage('SendSMSAcknowledgement', 'NexxtMobile Error:' . curl_error($ch));
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
}