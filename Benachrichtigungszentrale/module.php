<?php

/*
 * @module      Benachrichtigungszentrale
 *
 * @prefix      BENA
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @version     4.00-1
 * @date        2020-01-29, 18:00, 1580317200
 * @review      2020-01-29, 18:00,
 *
 * @see         https://github.com/ubittner/Benachrichtigungszentrale/
 *
 * @guids       Library
 *              {1961CDAF-8BEC-D073-B0E3-F793432E0628}
 *
 *              Benachrichtigungszentrale
 *             	{D184C522-507F-BED6-6731-728CE156D659}
 */

// Declare
declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Benachrichtigungszentrale extends IPSModule
{
    // Helper
    use BENA_notifications;

    // Constants
    private const WEBFRONT_MODULE_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const SMTP_MODULE_GUID = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register properties
        $this->RegisterProperties();

        // Register timers
        $this->RegisterTimers();

        // Register attributes
        $this->RegisterAttributes();

        // Register scripts
        $this->RegisterScripts();
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        // Never delete this line!
        parent::ApplyChanges();

        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }

        // Validate configuration
        $this->ValidateConfiguration();

        // Hide instance
        IPS_SetHidden($this->InstanceID, true);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Send debug
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

        }
    }

    protected function KernelReady()
    {
        $this->ApplyChanges();
    }

    //#################### Private

    private function RegisterProperties(): void
    {
        // Push notification
        $this->RegisterPropertyString('WebFronts', '[]');
        $this->RegisterPropertyBoolean('ConfirmAlarmNotification', false);
        $this->RegisterPropertyInteger('RepeatAlarmNotificationDurationTime', 60);
        $this->RegisterPropertyInteger('AlarmNotificationAttempts', 3);

        // E-Mail notification
        $this->RegisterPropertyString('MailRecipients', '[]');

        // SMS notification
        $this->RegisterPropertyString('SMSUser', '');
        $this->RegisterPropertyString('SMSToken', '');
        $this->RegisterPropertyString('SMSOriginator', '');
        $this->RegisterPropertyString('SMSRecipients', '[]');
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('RepeatAlarmNotification', 0, 'BENA_RepeatAlarmNotification(' . $this->InstanceID . ');');
    }

    private function DeactivateTimers(): void
    {
        $this->SetTimerInterval('RepeatAlarmNotification', 0);
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeString('AlarmNotificationTitle', '');
        $this->RegisterAttributeString('AlarmNotificationText', '');
        $this->RegisterAttributeInteger('AlarmNotificationAttempt', 0);
    }

    private function ResetAttributes(): void
    {
        $this->WriteAttributeString('AlarmNotificationTitle', '');
        $this->WriteAttributeString('AlarmNotificationText', '');
        $this->WriteAttributeInteger('AlarmNotificationAttempt', 0);
    }

    private function RegisterScripts(): void
    {
        $this->RegisterScript('ConfirmAlarmNotification', 'Alarmquittierung', "<?php BENA_ConfirmAlarmNotification(IPS_GetParent(\$_IPS['SELF']));");
        $resetScript = $this->GetIDForIdent('ConfirmAlarmNotification');
        IPS_SetPosition($resetScript, 1);
        IPS_SetHidden($resetScript, true);
    }

    private function ValidateConfiguration(): void
    {
        $state = 102;

        // Push notification
        $webFronts = json_decode($this->ReadPropertyString('WebFronts'));
        if (!empty($webFronts)) {
            foreach ($webFronts as $webFront) {
                if ($webFront->Use) {
                    $id = $webFront->ID;
                    if ($id != 0) {
                        if (!@IPS_ObjectExists($id)) {
                            $this->LogMessage('Instanzkonfiguration, Push Benachrichtigung, WebFront ID ungültig!', KL_ERROR);
                            $state = 200;
                        } else {
                            $instance = IPS_GetInstance($id);
                            $moduleID = $instance['ModuleInfo']['ModuleID'];
                            if ($moduleID !== self::WEBFRONT_MODULE_GUID) {
                                $this->LogMessage('Instanzkonfiguration,, Push Benachrichtigung, WebFront GUID ungültig!', KL_ERROR);
                                $state = 200;
                            }
                        }
                    } else {
                        $this->LogMessage('Instanzkonfiguration, Push Benachrichtigung, Kein WebFront ausgewählt!', KL_ERROR);
                        $state = 200;
                    }
                }
            }
        }

        // E-Mail recipients
        $recipients = json_decode($this->ReadPropertyString('MailRecipients'));
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                if ($recipient->Use) {
                    $id = $recipient->ID;
                    if ($id != 0) {
                        if (!@IPS_ObjectExists($id)) {
                            $this->LogMessage('Instanzkonfiguration, E-Mail Benachrichtigung, SMTP ID ungültig!', KL_ERROR);
                            $state = 200;
                        } else {
                            $instance = IPS_GetInstance($id);
                            $moduleID = $instance['ModuleInfo']['ModuleID'];
                            if ($moduleID !== self::SMTP_MODULE_GUID) {
                                $this->LogMessage('Instanzkonfiguration,, E-Mail Benachrichtigung, SMTP GUID ungültig!', KL_ERROR);
                                $state = 200;
                            }
                        }
                    } else {
                        $this->LogMessage('Instanzkonfiguration, E-Mail Benachrichtigung, Keine SMTP Instanz ausgewählt!', KL_ERROR);
                        $state = 200;
                    }
                    $address = $recipient->Address;
                    if (empty($address) || strlen($address) < 3) {
                        $this->LogMessage('Instanzkonfiguration,, E-Mail Benachrichtigung, Empfängeradresse zu kurz!', KL_ERROR);
                        $state = 200;
                    }
                }
            }
        }

        // SMS notification
        $recipients = json_decode($this->ReadPropertyString('SMSRecipients'));
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                if ($recipient->Use) {
                    if (empty($this->ReadPropertyString('SMSUser'))) {
                        $this->LogMessage('Instanzkonfiguration, SMS Benachrichtigung, Benutzer nicht angegeben!', KL_ERROR);
                        $state = 200;
                    }
                    if (empty($this->ReadPropertyString('SMSToken'))) {
                        $this->LogMessage('Instanzkonfiguration, SMS Benachrichtigung, Token nicht angegeben!', KL_ERROR);
                        $state = 200;
                    }
                    if (empty($this->ReadPropertyString('SMSOriginator'))) {
                        $this->LogMessage('Instanzkonfiguration, SMS Benachrichtigung, Absenderrufnummer nicht angegeben!', KL_ERROR);
                        $state = 200;
                    }
                    $phoneNumber = $recipient->PhoneNumber;
                    if (empty($phoneNumber) || strlen($phoneNumber) < 3) {
                        $this->LogMessage('Instanzkonfiguration, SMS Benachrichtigung, Empfängerrufnummer zu kurz!', KL_ERROR);
                        $state = 200;
                    }
                }
            }
        }

        // Set state
        $this->SetStatus($state);
    }
}
