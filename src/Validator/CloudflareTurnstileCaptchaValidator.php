<?php

namespace Zemasterkrom\CloudflareTurnstileBundle\Validator;

use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zemasterkrom\CloudflareTurnstileBundle\Client\CloudflareTurnstileClientInterface;
use Zemasterkrom\CloudflareTurnstileBundle\ErrorManager\CloudflareTurnstileErrorManager;
use Zemasterkrom\CloudflareTurnstileBundle\Exception\CloudflareTurnstileApiException;
use Zemasterkrom\CloudflareTurnstileBundle\Exception\CloudflareTurnstileException;
use Zemasterkrom\CloudflareTurnstileBundle\Exception\CloudflareTurnstileInvalidResponseException;

/**
 * This class represents the core component of the bundle.
 * It allows to validate or reject the response provided by the Cloudflare Turnstile captcha.
 */
class CloudflareTurnstileCaptchaValidator extends ConstraintValidator
{
    private RequestStack $requestStack;
    private CloudflareTurnstileErrorManager $errorManager;
    private CloudflareTurnstileClientInterface $client;

    /**
     * Constructor for the validator.
     *
     * @param RequestStack $requestStack The RequestStack for accessing the current request.
     * @param CloudflareTurnstileClientInterface $cloudflareTurnstileClient The HTTP client for making API requests.
     * @param CloudflareTurnstileErrorManager $cloudflareTurnstileErrorManager The error manager which handles or ignore Cloudflare Turnstile HTTP errors.
     */
    public function __construct(RequestStack $requestStack, CloudflareTurnstileErrorManager $cloudflareTurnstileErrorManager, CloudflareTurnstileClientInterface $cloudflareTurnstileClient)
    {
        $this->requestStack = $requestStack;
        $this->errorManager = $cloudflareTurnstileErrorManager;
        $this->client = $cloudflareTurnstileClient;
    }

    /**
     * Validates the Cloudflare Turnstile captcha response
     *
     * @param mixed $value Unused input value (captcha response comes from a dynamically generated hidden token named cf-turnstile-response)
     * @param Constraint $constraint The captcha constraint being validated
     *
     * @throws CloudflareTurnstileInvalidResponseException If the response returned by Cloudflare Turnstile has been modified / is invalid (if error manager handling is enabled)
     * @throws CloudflareTurnstileApiException If there's an issue with the Cloudflare Turnstile verification API call (if error manager handling is enabled)
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof CloudflareTurnstileCaptcha) {
            throw new UnexpectedTypeException($constraint, CloudflareTurnstileCaptcha::class);
        }

        try {
            $captchaResponseToken = $this->getProvidedCaptchaResponseToken();

            if (!$this->client->verify($captchaResponseToken)) {
                $this->context->buildViolation($constraint->message)->addViolation();
            }
        } catch (CloudflareTurnstileException $e) {
            $this->context->buildViolation($constraint->message)->addViolation();
            $this->errorManager->throwIfExplicitErrorsEnabled($e);
        }
    }

    /**
     * Returns the Cloudflare Turnstile Captcha token associated to the request
     *
     * @throws CloudflareTurnstileInvalidResponseException If the incoming request or the provided Cloudflare Turnstile captcha is invalid
     *
     * @return mixed Provided Cloudflare Turnstile Captcha response token, which should normally be a string if the request is correctly constructed
     */
    private function getProvidedCaptchaResponseToken()
    {
        try {
            if ($currentRequest = $this->requestStack->getCurrentRequest()) {
                /** @var mixed */
                $captchaResponseToken = $currentRequest->request->get('cf-turnstile-response');
            } else {
                throw new CloudflareTurnstileInvalidResponseException('Invalid incoming request. Please check the provided request.');
            }
        } catch (RequestExceptionInterface $e) {
            throw new CloudflareTurnstileInvalidResponseException('Invalid Cloudflare Turnstile response. Captcha must be unique.', $e);
        }

        // Keep consistent InputBag behavior between Symfony 5 and Symfony 6
        if (null !== $captchaResponseToken && !\is_scalar($captchaResponseToken) && !(\is_object($captchaResponseToken) && method_exists($captchaResponseToken, '__toString'))) {
            throw new CloudflareTurnstileInvalidResponseException('Invalid Cloudflare Turnstile response. Captcha must be unique.');
        }

        return $captchaResponseToken;
    }
}
