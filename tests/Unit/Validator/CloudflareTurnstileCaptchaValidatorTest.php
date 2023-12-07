<?php

namespace Zemasterkrom\CloudflareTurnstileBundle\Tests\Unit\Validator;

use DG\BypassFinals;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Zemasterkrom\CloudflareTurnstileBundle\Client\CloudflareTurnstileClient;
use Zemasterkrom\CloudflareTurnstileBundle\Client\CloudflareTurnstileClientInterface;
use Zemasterkrom\CloudflareTurnstileBundle\ErrorManager\CloudflareTurnstileErrorManager;
use Zemasterkrom\CloudflareTurnstileBundle\Exception\CloudflareTurnstileApiException;
use Zemasterkrom\CloudflareTurnstileBundle\Exception\CloudflareTurnstileInvalidResponseException;
use Zemasterkrom\CloudflareTurnstileBundle\Validator\CloudflareTurnstileCaptcha;
use Zemasterkrom\CloudflareTurnstileBundle\Validator\CloudflareTurnstileCaptchaValidator;

/**
 * Clouflare turnstile validator test class.
 * Checks that form violation errors are raised, that exception handling is correct and that client verification is correctly connected.
 */
class CloudflareTurnstileCaptchaValidatorTest extends ConstraintValidatorTestCase
{
    private CloudflareTurnstileErrorManager $errorManager;
    private RequestStack $mockedRequestStack;
    private string $mockedValidationResponse;

    /**
     * Initializes default properties for the test and the validator :
     * - Disabled error manager ;
     * - Default request ;
     * - Empty JSON message.
     */
    public function setUp(): void
    {
        BypassFinals::enable();

        $this->errorManager = new CloudflareTurnstileErrorManager(false);
        $this->mockedRequestStack = new RequestStack();
        $this->mockedRequestStack->push(new Request());
        $this->mockedValidationResponse = json_encode([]); // @phpstan-ignore-line

        parent::setUp();
    }

    /**
     * Creates the validator from the properties of the current test
     *
     * @return CloudflareTurnstileCaptchaValidator Cloudflare Turnstile Captcha Validator with current test properties
     */
    protected function createValidator(): CloudflareTurnstileCaptchaValidator
    {
        $this->validator = new CloudflareTurnstileCaptchaValidator($this->mockedRequestStack, $this->errorManager, new CloudflareTurnstileClient(new MockHttpClient(new MockResponse($this->mockedValidationResponse)), '', []));
        $this->context = $this->createContext();
        $this->validator->initialize($this->context);

        return $this->validator;
    }

    /**
     * Creates the validator from the properties of the current test with a custom client implementation.
     * Used for abstracting and hiding implementation details of requests.
     *
     * @param CloudflareTurnstileClientInterface $client Custom client interfaced object
     *
     * @return CloudflareTurnstileCaptchaValidator Cloudflare Turnstile Captcha Validator with current test properties
     */
    protected function createValidatorWithCustomClient(CloudflareTurnstileClientInterface $client): CloudflareTurnstileCaptchaValidator
    {
        $this->validator = new CloudflareTurnstileCaptchaValidator($this->mockedRequestStack, $this->errorManager, $client);
        $this->context = $this->createContext();
        $this->validator->initialize($this->context);

        return $this->validator;
    }

    public function testSuccessfulCaptchaValidation(): void
    {
        $this->mockedRequestStack->push(new Request([], [
            'cf-turnstile-response' => true
        ]));

        /** @phpstan-ignore-next-line */
        $this->mockedValidationResponse = json_encode([
            'success' => true
        ]);

        $this->validator = $this->createValidator();
        $this->validator->validate('', new CloudflareTurnstileCaptcha());

        $this->assertNoViolation();
    }

    public function testUnsuccessfulCaptchaValidation(): void
    {
        /** @phpstan-ignore-next-line */
        $this->mockedValidationResponse = json_encode([
            'success' => false
        ]);

        $this->validator = $this->createValidator();
        $this->validator->validate('', new CloudflareTurnstileCaptcha());

        $this->buildViolation('rejected_captcha')->assertRaised();

        $violations = $this->context->getViolations();
    }

    public function testInvalidCaptchaConstraintThrowsException(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('', $this->createMock(Constraint::class));
    }

    public function testInvalidCaptchaResponse(): void
    {
        $this->expectException(CloudflareTurnstileInvalidResponseException::class);

        $this->errorManager = new CloudflareTurnstileErrorManager(true);

        // Mocked ParameterBag to keep consistent get behavior between Symfony 5 and 6
        /** @var \Symfony\Component\HttpFoundation\InputBag&ParameterBag&MockObject */
        /** @phpstan-ignore-next-line */
        $mockedParameterBag = class_exists('\Symfony\Component\HttpFoundation\InputBag') ? $this->createMock('\Symfony\Component\HttpFoundation\InputBag') : $this->createMock(ParameterBag::class);

        /*
        Before Symfony 6, non-scalar parameters were allowed on the get operation.
        To maintain consistency between captcha verification logic and Symfony versions, this is not allowed and is verified since a form cannot contain multiple captchas.
        */
        if (Kernel::VERSION_ID < 60000) {
            $mockedParameterBag->method('get')
                ->willReturn([]);
        } else {
            // Since Symfony 6, parameters must be typed. It is not possible to break the contract (as a TypeError would be thrown)
            $mockedParameterBag->method('get')
                ->willThrowException(new class extends \UnexpectedValueException implements RequestExceptionInterface
                {
                });
        }

        $mockedRequest = $this->createMock(Request::class);
        $mockedRequest->request = $mockedParameterBag;

        // The simulated RequestStack will return the simulated Request with consistent behavior, which contains non-scalar data and should raise an error during validation
        $mockedRequestStack = $this->createMock(RequestStack::class);
        $this->mockedRequestStack = $mockedRequestStack;

        $mockedRequestStack->method('getCurrentRequest')
            ->willReturn($mockedRequest);

        $this->validator = $this->createValidator();
        $this->validator->validate('', new CloudflareTurnstileCaptcha());

        // Even if Symfony 5 behavior is currently retained, the received captcha is still invalid!
        $this->buildViolation('rejected_captcha')->assertRaised();
    }

    /**
     * This test asserts that exception handling is handled correctly to take into account the fact that the Cloudflare turnstile request may be malformed
     */
    public function testInvalidCloudflareTurnstileRequest(): void
    {
        $this->expectException(CloudflareTurnstileInvalidResponseException::class);

        $this->errorManager = new CloudflareTurnstileErrorManager(true);

        /** @var \Symfony\Component\HttpFoundation\InputBag&ParameterBag&MockObject */
        /** @phpstan-ignore-next-line */
        $mockedParameterBag = class_exists('\Symfony\Component\HttpFoundation\InputBag') ? $this->createMock('\Symfony\Component\HttpFoundation\InputBag') : $this->createMock(ParameterBag::class);
        $mockedParameterBag->method('get')
            ->willThrowException(new class extends \UnexpectedValueException implements RequestExceptionInterface
            {
            });

        $mockedRequest = $this->createMock(Request::class);
        $mockedRequest->request = $mockedParameterBag;

        $mockedRequestStack = $this->createMock(RequestStack::class);
        $this->mockedRequestStack = $mockedRequestStack;

        $mockedRequestStack->method('getCurrentRequest')
            ->willReturn($mockedRequest);

        $this->validator = $this->createValidator();
        $this->validator->validate('', new CloudflareTurnstileCaptcha());

        $this->buildViolation('rejected_captcha')->assertRaised();
    }

    public function testInvalidCaptchaClientValidation(): void
    {
        $this->errorManager = new CloudflareTurnstileErrorManager(false);

        $mockedClient = $this->createMock(CloudflareTurnstileClientInterface::class);
        $mockedClient->method('verify')
            ->willThrowException(new CloudflareTurnstileApiException(__METHOD__));

        $this->validator = $this->createValidatorWithCustomClient($mockedClient);
        $this->validator->validate('', new CloudflareTurnstileCaptcha());

        $this->buildViolation('rejected_captcha')->assertRaised();
    }
}
