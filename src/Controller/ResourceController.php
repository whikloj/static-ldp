<?php

namespace Trellis\StaticLdp\Controller;

use Silex\Api\ControllerProviderInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ResourceController implements ControllerProviderInterface
{

  /**
   * {@inheritdoc}
   */
  public function connect(Application $app)
  {
    $controllers = $app['controllers_factory'];
    //
    // Define routing referring to controller services
    //

    // Options
    $controllers->options("/{path}", "staticldp.resourcecontroller:options")
      ->assert('path', '.+')
      ->value('path', '')
      ->bind('staticldp.serverOptions');

    // Generic GET.
    $controllers->match("/{path}", "staticldp.resourcecontroller:getOrHead")
      ->method('HEAD|GET')
      ->assert('path', '.+')
      ->value('path', '')
      ->bind('staticldp.resourceGetOrHead');

    return $controllers;
  }

  /**
   * Perform the GET or HEAD request.
   *
   * @param \Silex\Application $app
   *   The Silex application.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param $path
   *   The path parameter from the request.
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function getOrHead(Application $app, Request $request, $path)
  {

    // Get default responseFormat.
    $responseFormat = $app['config']['defaultRdfFormat'];

    $docroot = $app['config']['sourceDirectory'];
    if (!empty($path)) {
      $path = "/{$path}";
    }

    $requested_path = "{$docroot}{$path}";
    if (!file_exists($requested_path)) {
      return new Response("Not Found", 404);
    }

    if ($request->headers->has('accept')) {
      $format = $this->getResponseFormat($app['config']['validRdfFormats'], $request);
      if (!is_null($format)) {
        $responseFormat = $format;
      }
    }


    // It is a file.
    if (is_file($requested_path)) {
      $response = $this->getFile(
        $requested_path,
        $responseFormat,
        $app['config']['validRdfFormats'],
        ($request->getMethod() == 'GET')
      );

    } else {
      // We assume it's a directory.
      $response = $this->getDirectory(
        $requested_path,
        $responseFormat,
        $app['config']['validRdfFormats'],
        ($request->getMethod() == 'GET')
      );
    }
    return $response;
  }

  /**
   * Response to a generic options request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function options()
  {
    $headers = [
      "Allow" => "OPTIONS, GET, HEAD",
    ];
    return new Response('', 200, $headers);
  }

  /**
   * Find the valid mimeType and
   *
   * @param array $validRdfFormats
   *   Supported formats from the config.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   EasyRdf "format" or null if not supported.
   */
  private function getResponseFormat(array $validRdfFormats, Request $request)
  {
    if ($request->headers->has('accept')) {
      $accept = $request->getAcceptableContentTypes();
      foreach ($accept as $item) {
        $index = array_search($item, array_column($validRdfFormats, 'mimeType'));
        if ($index !== FALSE) {
          return $validRdfFormats[$index]['format'];
        }
      }
    }
    return null;
  }

  /**
   * Serve a file from the filesystem.
   *
   * @param $path
   *   Path to file we are serving.
   * @param $responseFormat
   *   The format to respond in, if it is a RDFSource.
   * @param array $validRdfFormats
   *   The configured validRdfFormats.
   * @param boolean $doGet
   *   Whether we are doing a GET or HEAD request.
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function getFile($path, $responseFormat, array $validRdfFormats, $doGet = false) {
    $headers = [];
    // Plain might be RDF, check the file extension.
    $dirChunks = explode(DIRECTORY_SEPARATOR, $path);
    $filename = array_pop($dirChunks);
    $filenameChunks = explode('.', $filename);
    $extension = array_pop($filenameChunks);
    $index = array_search($extension, array_column($validRdfFormats, 'extension'));
    if ($index !== FALSE) {
      // This is a RDF file.
      $inputFormat = $validRdfFormats[$index]['format'];
      $headers["Link"] = ["<http://www.w3.org/ns/ldp#Resource>; rel=\"type\"",
                          "<http://www.w3.org/ns/ldp#RDFSource>; rel=\"type\""];
      $headers["Vary"] = "Accept";
      $subject = $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];

      // Converting RDF from something to something else.
      $graph = new \EasyRdf_Graph();
      $graph->parseFile($path, $inputFormat, $subject);
      $content = $graph->serialise($responseFormat);
      $headers["Content-Length"] = strlen($content);

      $index = array_search($responseFormat, array_column($validRdfFormats, 'format'));
      if ($index !== false) {
        $headers['Content-Type'] = $validRdfFormats[$index]['mimeType'];
      }
    } else {
      // This is not a RDF file.
      $contentLength = filesize($path);
      $responseMimeType = mime_content_type($path);
      $headers = [
        "Content-Type" => $responseMimeType,
        "Link" => ["<http://www.w3.org/ns/ldp#Resource>; rel=\"type\"",
                   "<http://www.w3.org/ns/ldp#NonRDFSource>; rel=\"type\""],
        "Content-Length" => $contentLength,
      ];

      if ($doGet) {
        // Probably best to stream the data out.
        // http://silex.sensiolabs.org/doc/2.0/usage.html#streaming
        $content = file_get_contents($path);
      }
    }
    if (!$doGet) {
      $content = '';
    }
    return new Response($content, 200, $headers);
  }

  /**
   * @param $path
   *   Path to file we are serving.
   * @param $responseFormat
   *   The format to respond in, if it is a RDFSource.
   * @param array $validRdfFormats
   *   The configured validRdfFormats.
   * @param boolean $doGet
   *   Whether we are doing a GET or HEAD request.
   * @return \Symfony\Component\HttpFoundation\Response
   */
  private function getDirectory($path, $responseFormat, array $validRdfFormats, $doGet = false) {

    $subject = $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $predicate = "http://www.w3.org/ns/ldp#contains";

    $index = array_search($responseFormat, array_column($validRdfFormats, 'format'));
    if ($index !== false) {
      $responseMimeType = $validRdfFormats[$index]['mimeType'];
    }
    $headers = [
      "Link" => ["<http://www.w3.org/ns/ldp#Resource>; rel=\"type\"",
                 "<http://www.w3.org/ns/ldp#BasicContainer>; rel=\"type\""],
      "Vary" => "Accept",
      "Content-Type" => $responseMimeType,
    ];
    $namespaces = new \EasyRdf_Namespace();
    $namespaces->set("ldp", "http://www.w3.org/ns/ldp#");
    $namespaces->set("dc", "http://purl.org/dc/terms/");

    $graph = new \EasyRdf_Graph();
    $graph->addLiteral($subject, "http://purl.org/dc/terms/modified", new \DateTime(date('c', filemtime($path))));

    foreach (new \DirectoryIterator($path) as $fileInfo) {
      if ($fileInfo->isDot()) {
        continue;
      }
      $graph->addResource($subject, $predicate, rtrim($subject, '/') . '/' . ltrim($fileInfo->getFilename(), '/'));
    }

    $content = $graph->serialise($responseFormat);
    $headers["Content-Length"] = strlen($content);
    if (!$doGet) {
      $content = '';
    }
    return new Response($content, 200, $headers);
  }
}