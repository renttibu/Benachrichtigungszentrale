<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Benachrichtigungszentrale/
 */

/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Benachrichtigungszentrale extends IPSModule
{
    // Helper
    use BZ_backupRestore;
    use BZ_notifications;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        // Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        // Push notification (WebFront)
        $this->RegisterPropertyBoolean('UsePushNotification', false);
        $this->RegisterPropertyString('WebFronts', '[]');
        $this->RegisterPropertyBoolean('ConfirmAlarmMessage', false);
        $this->RegisterPropertyInteger('ConfirmationPeriod', 60);
        $this->RegisterPropertyInteger('AlarmNotificationAttempts', 3);
        // E-Mail (SMTP) notification
        $this->RegisterPropertyBoolean('UseSMTPNotification', false);
        $this->RegisterPropertyString('SMTPRecipients', '[]');
        // SMS (NeXXt Mobile) notification
        $this->RegisterPropertyBoolean('UseNexxtMobile', false);
        $this->RegisterPropertyString('NexxtMobileToken', '');
        $this->RegisterPropertyString('NexxtMobileSenderPhoneNumber', '');
        $this->RegisterPropertyInteger('NexxtMobileTimeout', 5000);
        $this->RegisterPropertyString('NexxtMobileRecipients', '[]');
        // SMS (Sipgate) notification
        $this->RegisterPropertyBoolean('UseSipgate', false);
        $this->RegisterPropertyString('SipgateUser', '');
        $this->RegisterPropertyString('SipgatePassword', '');
        $this->RegisterPropertyInteger('SipgateTimeout', 5000);
        $this->RegisterPropertyString('SipgateRecipients', '[]');

        // Attributes
        $this->RegisterAttributeString('AlarmNotificationTitle', '');
        $this->RegisterAttributeString('AlarmNotificationText', '');
        $this->RegisterAttributeInteger('AlarmNotificationAttempt', 0);

        // Timer
        $this->RegisterTimer('RepeatAlarmNotification', 0, 'BZ_RepeatAlarmNotification(' . $this->InstanceID . ');');

        // Script
        $id = @$this->GetIDForIdent('ConfirmAlarmNotification');
        $this->RegisterScript('ConfirmAlarmNotification', 'Alarmquittierung', "<?php BZ_ConfirmAlarmNotification(IPS_GetParent(\$_IPS['SELF']));");
        if ($id == false) {
            IPS_SetPosition($this->GetIDForIdent('ConfirmAlarmNotification'), 10);
            IPS_SetHidden($this->GetIDForIdent('ConfirmAlarmNotification'), true);
        }
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

    private function ValidateConfiguration(): bool
    {
        $result = true;
        $status = 102;
        // Push notification
        if ($this->ReadPropertyBoolean('UsePushNotification')) {
            $webFronts = json_decode($this->ReadPropertyString('WebFronts'));
            if (!empty($webFronts)) {
                foreach ($webFronts as $webFront) {
                    if ($webFront->Use) {
                        $id = $webFront->ID;
                        if ($id == 0 || !@IPS_ObjectExists($id)) {
                            $status = 200;
                            $text = 'Bitte die zugewiesenen WebFront Instanzen überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                    }
                }
            }
        }

        // E-Mail recipients
        if ($this->ReadPropertyBoolean('UseSMTPNotification')) {
            $recipients = json_decode($this->ReadPropertyString('SMTPRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        $id = $recipient->ID;
                        if ($id == 0 || !@IPS_ObjectExists($id)) {
                            $status = 200;
                            $text = 'Bitte die zugewiesenen SMTP Instanzen überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                        $address = $recipient->Address;
                        if (empty($address) || strlen($address) < 3) {
                            $status = 200;
                            $text = 'Bitte die zugewiesenen E-Mail Empfänger überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                    }
                }
            }
        }

        // SMS (NeXXt mobile) notification
        if ($this->ReadPropertyBoolean('UseNexxtMobile')) {
            $recipients = json_decode($this->ReadPropertyString('NexxtMobileRecipients'));
            if (!empty($recipients)) {
                foreach ($recipients as $recipient) {
                    if ($recipient->Use) {
                        if (empty($this->ReadPropertyString('NexxtMobileToken'))) {
                            $status = 200;
                            $text = 'Bitte den angegebenen NeXXt Mobile Token überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                        if (empty($this->ReadPropertyString('NexxtMobileSenderPhoneNumber'))) {
                            $status = 200;
                            $text = 'Bitte die angegebene NeXXt Mobile Absender Rufnummer überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                        $phoneNumber = $recipient->PhoneNumber;
                        if (empty($phoneNumber) || strlen($phoneNumber) < 3) {
                            $status = 200;
                            $text = 'Bitte die angegebenen NeXXt Mobile  Empfänger Rufnummern überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
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
                            $status = 200;
                            $text = 'Bitte den angegebenen sipgate Benutzer überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                        if (empty($this->ReadPropertyString('SipgatePassword'))) {
                            $status = 200;
                            $text = 'Bitte das angegebenen sipgate Kennwort überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                        $phoneNumber = $recipient->PhoneNumber;
                        if (empty($phoneNumber) || strlen($phoneNumber) < 3) {
                            $status = 200;
                            $text = 'Bitte die angegebenen sipgate Empfänger Rufnummern überprüfen!';
                            $this->SendDebug(__FUNCTION__, $text, 0);
                            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                        }
                    }
                }
            }
        }

        // Maintenance mode
        $maintenance = $this->CheckMaintenanceMode();
        if ($maintenance) {
            $status = 104;
        }
        IPS_SetDisabled($this->InstanceID, $maintenance);
        $this->SetStatus($status);
        if ($status != 102) {
            $result = false;
        }
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