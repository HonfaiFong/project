<?php

declare(strict_types=1);

namespace Packages\Phone;

use Contracts\Errors\ErrorCode;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use RuntimeException;

final class PhoneNormalizer
{
    private PhoneNumberUtil $phoneNumberUtil;

    public function __construct(private readonly string $defaultRegion = 'CN')
    {
        $this->phoneNumberUtil = PhoneNumberUtil::getInstance();
    }

    public function normalize(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            throw $this->invalidPhone();
        }

        try {
            $number = $this->phoneNumberUtil->parse($phone, $this->defaultRegion);
        } catch (NumberParseException) {
            throw $this->invalidPhone();
        }

        if (!$this->phoneNumberUtil->isValidNumber($number)) {
            throw $this->invalidPhone();
        }

        return $this->phoneNumberUtil->format($number, PhoneNumberFormat::E164);
    }

    private function invalidPhone(): RuntimeException
    {
        return new RuntimeException('phone is invalid', ErrorCode::INVALID_ARGUMENT->value);
    }
}
