<?php

namespace Zemasterkrom\CloudflareTurnstileBundle\Tests\Unit\ErrorManager;

use PHPUnit\Framework\TestCase;
use Zemasterkrom\CloudflareTurnstileBundle\ErrorManager\CloudflareTurnstileErrorManager;
use Zemasterkrom\CloudflareTurnstileBundle\Exception\CloudflareTurnstileException;

/**
 * Cloudflare Turnstile exception / error manager test class.
 * Checks that exception throwing is properly enabled / disabled / configured.
 */
class CloudflareTurnstileErrorManagerTest extends TestCase
{
    public function testExceptionThrowingWithEnabledCoreFailureHandling(): void
    {
        $this->expectException(CloudflareTurnstileException::class);

        $errorManager = new CloudflareTurnstileErrorManager(true);
        $errorManager->throwIfExplicitErrorsEnabled(new CloudflareTurnstileException(''));
    }

    public function testNoExceptionThrowingWithDisabledCoreFailureHandling(): void
    {
        $this->expectNotToPerformAssertions();

        $errorManager = new CloudflareTurnstileErrorManager(false);
        $errorManager->throwIfExplicitErrorsEnabled(new CloudflareTurnstileException(''));
    }
}
