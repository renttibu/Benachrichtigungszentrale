<?php

/** @noinspection PhpUnused */

/*
 * @module      Benachrichtigungszentrale 1
 *
 * @prefix      BZ1
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Benachrichtigungszentrale/
 *
 * @guids       Library
 *              {1961CDAF-8BEC-D073-B0E3-F793432E0628}
 *
 *              Benachrichtigungszentrale 1
 *             	{0A9D7D1E-286F-BC3C-F162-7622A53EBE5A}
 */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Benachrichtigungszentrale1 extends IPSModule
{
    //Helper
    use BZ1_backupRestore;
    use BZ1_notifications;

    //Constants
    private const WEBFRONT_MODULE_GUID = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    private const SMTP_MODULE_GUID = '{375EAF21-35EF-4BC4-83B3-C780FD8BD88A}';

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->RegisterTimers();
        $this->RegisterAttributes();
        $this->RegisterScripts();
    }

    public function ApplyChanges()
    {
        //Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        //Never delete this line!
        parent::ApplyChanges();
        //Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->ValidateConfiguration();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug('MessageSink', 'Message from SenderID ' . $SenderID . ' with Message ' . $Message . "\r\n Data: " . print_r($Data, true), 0);
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function RegisterProperties(): void
    {
        //Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        //Push notification (WebFront)
        $this->RegisterPropertyBoolean('UsePushNotification', false);
        $this->RegisterPropertyString('WebFronts', '[]');
        $this->RegisterPropertyBoolean('ConfirmAlarmMessage', false);
        $this->RegisterPropertyInteger('ConfirmationPeriod', 60);
        $this->RegisterPropertyInteger('AlarmNotificationAttempts', 3);
        //E-Mail (SMTP) notification
        $this->RegisterPropertyBoolean('UseSMTPNotification', false);
        $this->RegisterPropertyString('SMTPRecipients', '[]');
        //SMS (Nexxt Mobile) notification
        $this->RegisterPropertyBoolean('UseNexxtMobile', false);
        $this->RegisterPropertyString('NexxtMobileToken', '');
        $this->RegisterPropertyString('NexxtMobileSenderPhoneNumber', '');
        $this->RegisterPropertyInteger('NexxtMobileTimeout', 5000);
        $this->RegisterPropertyString('NexxtMobileRecipients', '[]');
        //SMS (Sipgate) notification
        $this->RegisterPropertyBoolean('UseSipgate', false);
        $this->RegisterPropertyString('SipgateUser', '');
        $this->RegisterPropertyString('SipgatePassword', '');
        $this->RegisterPropertyInteger('SipgateTimeout', 5000);
        $this->RegisterPropertyString('SipgateRecipients', '[]');
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('RepeatAlarmNotification', 0, 'BZ1_RepeatAlarmNotification(' . $this->InstanceID . ');');
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
        $this->RegisterScript('ConfirmAlarmNotification', 'Alarmquittierung', "<?php BZ1_ConfirmAlarmNotification(IPS_GetParent(\$_IPS['SELF']));");
        $resetScript = $this->GetIDForIdent('ConfirmAlarmNotification');
        IPS_SetPosition($resetScript, 10);
        IPS_SetHidden($resetScript, true);
    }

    private function ValidateConfiguration(): bool
    {
        $result = true;
        $status = 102;
        //Push notification
        if ($this->ReadPropertyBoolean('UsePushNotification')) {
            $webFronts = json_decode($this->ReadPropertyString('WebFronts'));
            if (!empty($webFronts)) {
                foreach ($webFronts as $webFront) {
                    if ($webFront->Use) {
                        $id = $webFront->ID;
                        if ($id != 0) {
                            if (!@IPS_ObjectExists($id)) {
                                $this->LogMessage('Instanzkonfiguration, Push Benachrichtigung, WebFront ID ungültig!', KL_ERROR);
                                $result = false;
                                $status = 200;
                            } else {
                                $instance = IPS_GetInstance($id);
                                $moduleID = $instance['ModuleInfo']['ModuleID'];
                                if ($moduleID !== self::WEBFRONT_MODULE_GUID) {
                                    $this->LogMessage('Instanzkonfiguration,, Push Benachrichtigung, WebFront GUID ungültig!', KL_ERROR);
                                    $result = false;
                                    $status = 200;
                                }
                            }
                        } else {
                            $this->LogMessage('Instanzkonfiguration, Push Benachrichtigung, Kein WebFront ausgewählt!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                    }
                }
            }
        }
        //E-Mail recipients
        if ($this->ReadPropertyBoolean('UseSMTPNotification')) {
            $recipients = json_decode($this->ReadPropertyString('SMTPRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        $id = $recipient->ID;
                        if ($id != 0) {
                            if (!@IPS_ObjectExists($id)) {
                                $this->LogMessage('Instanzkonfiguration, E-Mail Benachrichtigung, SMTP ID ungültig!', KL_ERROR);
                                $result = false;
                                $status = 200;
                            } else {
                                $instance = IPS_GetInstance($id);
                                $moduleID = $instance['ModuleInfo']['ModuleID'];
                                if ($moduleID !== self::SMTP_MODULE_GUID) {
                                    $this->LogMessage('Instanzkonfiguration,, E-Mail Benachrichtigung, SMTP GUID ungültig!', KL_ERROR);
                                    $result = false;
                                    $status = 200;
                                }
                            }
                        } else {
                            $this->LogMessage('Instanzkonfiguration, E-Mail Benachrichtigung, Keine SMTP Instanz ausgewählt!', KL_ERROR);
                            $status = 200;
                        }
                        $address = $recipient->Address;
                        if (empty($address) || strlen($address) < 3) {
                            $this->LogMessage('Instanzkonfiguration,, E-Mail Benachrichtigung, Empfängeradresse zu kurz!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                    }
                }
            }
        }
        //SMS (Nexxt mobile) notification
        if ($this->ReadPropertyBoolean('UseNexxtMobile')) {
            $recipients = json_decode($this->ReadPropertyString('NexxtMobileRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        if (empty($this->ReadPropertyString('NexxtMobileToken'))) {
                            $this->LogMessage('Instanzkonfiguration, Nexxt Mobile SMS Benachrichtigung, Token nicht angegeben!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                        if (empty($this->ReadPropertyString('NexxtMobileSenderPhoneNumber'))) {
                            $this->LogMessage('Instanzkonfiguration, Nexxt Mobile SMS Benachrichtigung, Absenderrufnummer nicht angegeben!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                        $phoneNumber = $recipient->PhoneNumber;
                        if (empty($phoneNumber) || strlen($phoneNumber) < 3) {
                            $this->LogMessage('Instanzkonfiguration, Nexxt Mobile SMS Benachrichtigung, Empfängerrufnummer zu kurz!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                    }
                }
            }
        }
        if ($this->ReadPropertyBoolean('UseSipgate')) {
            $recipients = json_decode($this->ReadPropertyString('SipgateRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        if (empty($this->ReadPropertyString('SipgateUser'))) {
                            $this->LogMessage('Instanzkonfiguration, Sipgate SMS Benachrichtigung, Benutzer nicht angegeben!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                        if (empty($this->ReadPropertyString('SipgatePassword'))) {
                            $this->LogMessage('Instanzkonfiguration, Sipgate SMS Benachrichtigung, Passwort nicht angegeben!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                        $phoneNumber = $recipient->PhoneNumber;
                        if (empty($phoneNumber) || strlen($phoneNumber) < 3) {
                            $this->LogMessage('Instanzkonfiguration, Sipgate SMS Benachrichtigung, Empfängerrufnummer zu kurz!', KL_ERROR);
                            $result = false;
                            $status = 200;
                        }
                    }
                }
            }
        }
        //Maintenance mode
        $maintenance = $this->CheckMaintenanceMode();
        if ($maintenance) {
            $result = false;
            $status = 104;
        }
        IPS_SetDisabled($this->InstanceID, $maintenance);
        $this->SetStatus($status);
        return $result;
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = $this->ReadPropertyBoolean('MaintenanceMode');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wartungsmodus ist aktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, der Wartungsmodus ist aktiv!', KL_WARNING);
        }
        return $result;
    }
}