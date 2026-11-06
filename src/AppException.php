<?php
declare(strict_types=1);
namespace GeoTexture;

final class AppException extends \RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 400) {
        parent::__construct($message);
    }
}
