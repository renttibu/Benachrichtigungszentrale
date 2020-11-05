<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class BenachrichtigungszentraleValidationTest extends TestCaseSymconValidation
{
    public function testValidateBenachrichtigungszentrale(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateBenachrichtigungszentraleModule(): void
    {
        $this->validateModule(__DIR__ . '/../Benachrichtigungszentrale');
    }

    public function testValidateBenachrichtigungszentrale1Module(): void
    {
        $this->validateModule(__DIR__ . '/../Benachrichtigungszentrale 1');
    }
}