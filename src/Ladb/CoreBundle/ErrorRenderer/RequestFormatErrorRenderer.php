<?php

namespace Ladb\CoreBundle\ErrorRenderer;

use Symfony\Component\ErrorHandler\ErrorRenderer\ErrorRendererInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * The error page of a request in another format than HTML (routes with a "_format" default, the
 * .json and .geojson ones): @Twig/Exception/error.{format}.twig, as the ExceptionController of
 * TwigBundle did before Symfony 4.4. TwigErrorRenderer only renders HTML.
 */
final class RequestFormatErrorRenderer implements ErrorRendererInterface {

	private $errorRenderer;
	private $requestStack;
	private $twig;
	private $debug;

	/**
	 * @param callable $debug HtmlErrorRenderer::isDebug(): in debug, the exception page of HtmlErrorRenderer
	 */
	public function __construct(ErrorRendererInterface $errorRenderer, RequestStack $requestStack, Environment $twig, callable $debug) {
		$this->errorRenderer = $errorRenderer;
		$this->requestStack = $requestStack;
		$this->twig = $twig;
		$this->debug = $debug;
	}

	public function render(\Throwable $exception): FlattenException {
		$flattenException = $this->errorRenderer->render($exception);

		// The request of the error controller, in the format of the request that failed
		$request = $this->requestStack->getCurrentRequest();
		$format = $request->getRequestFormat();
		$template = '@Twig/Exception/error.'.$format.'.twig';
		if ('html' === $format || ($this->debug)($exception) || !$this->twig->getLoader()->exists($template)) {
			return $flattenException;
		}

		$flattenException->setHeaders(array_merge($flattenException->getHeaders(), array( 'Content-Type' => $request->getMimeType($format) )));

		return $flattenException->setAsString($this->twig->render($template, array(
			'status_code' => $flattenException->getStatusCode(),
			'status_text' => $flattenException->getStatusText(),
		)));
	}

}
