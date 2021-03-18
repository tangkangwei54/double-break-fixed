<?php

/**
* Amazon Selling Partner API client generator
* @author Lyubo Slavilov
* This file is used to generate clients for Apazon Selling Partner API
* by using the OpenAPI (f.k.a Swagger) specifictaion provided by Amazon
* https://github.com/amzn/selling-partner-api-models/tree/main/models
*
*/


include __DIR__ . '/vendor/autoload.php';

class SpapiGenerator {
  public $className;

  private $specURL;

  private $swaggerSpec;
  private $parsedSpec;
  private $jsonFileName;

  public function __construct($specURL, $model, $jsonFileName)
  {
    $this->specURL = $specURL;

    $this->className = implode('', array_map(
      function($p){ return ucfirst($p); },
      array_slice(explode('-', $model), 0, -2)
    )); //duuh.. it's just a salammi-case to BorlandCase transformation;

    $this->model = $model;
    $this->jsonFileName = $jsonFileName;

  }

  public function loadSpec()
  {
    $client = new GuzzleHttp\Client(['verify'=>false]);

    $contents = file_get_contents($this->specURL);
    $this->swaggerSpec = json_decode($contents, true);
  }

  public function parseSpec()
  {
    $parsedSpec = [];
    $superGlobals = $this->swaggerSpec['parameters'] ?? [];

    foreach ($this->swaggerSpec['paths'] as $path => $spec) {
      $globalParams = $spec['parameters'] ?? [];
      unset($spec['parameters']);

      foreach ($spec as $httpMethod => $methodSpec) {
        $parameters = array_merge($globalParams,$methodSpec['parameters'] ?? []);
        $parsedParams = $this->parsePathParameters($parameters, $superGlobals);
        $parsedSpec[] = [
          'pathTemplate' => $path,
          'httpMethod' => $httpMethod,
          'classMethod' => $methodSpec['operationId'],
          'queryParams' => $parsedParams['query'],
          'pathParams' => $parsedParams['path'],
          'bodyParams' => $parsedParams['body']
        ];
      }
    }
    $this->parsedSpec = $parsedSpec;
  }

  public function generateCode()
  {
    $source = $this->generateHeader('');
    $source .= $this->generateMethods('  ');
    $source .= $this->line('', '}'); //close the class block

    $sign = md5($source);
    $source = str_replace('<MD5-SIGNATURE>', $sign, $source);
    return $source;
  }

  //Parsing helpers
  private function toCamel($varname)
  {
    $words = explode('_', $varname);

    $first = lcfirst(array_shift($words));

    $words = array_map(fn($word) => ucfirst($word), $words);

    array_unshift($words, $first);
    return join('', $words);
  }

  private function remapParameter($param)
  {
    return [
      'name' => $param['name'],
      'type' => $param['type'] ?? '',
      'desc' => $param['description'] ?? ''
    ];
  }

  private function parsePathParameters($specParameters, $superGlobals)
  {
    $parsedParams = [
      'query' => [],
      'path' => [],
      'body' => []
    ];
    foreach ($specParameters as $param) {
      if (isset($param['$ref']) && strpos($param['$ref'], '#/parameters/') === 0) {
        $p = $superGlobals[str_replace('#/parameters/', '', $param['$ref'])];
        $parsedParams[$p['in']][] = $this->remapParameter($p);
      } else {
        $parsedParams[$param['in']][] = $this->remapParameter($param);
      }
    }
    return $parsedParams;
  }


  //Code generation helpers
  private function line($idn = '', $str = '')
  {
    return $idn . $str . "\n";
  }

  private function generateHeader($idn = '')
  {

    $header =  $this->line($idn, "<?php");

    $header .= $this->line($idn, "/**");
    $header .= $this->line($idn, "* This class is autogenerated by the Spapi class generator");
    $header .= $this->line($idn, "* Date of generation: " . date('Y-m-d', time()));
    $header .= $this->line($idn, "* Specification: ttps://github.com/amzn/selling-partner-api-models/blob/main/models/{$this->model}/{$this->jsonFileName}");
    $header .= $this->line($idn, "* Source MD5 signature: <MD5-SIGNATURE>");
    $header .= $this->line($idn, "*");
    $header .= $this->line($idn, "*");
    $header .= $this->line($idn, "* {$this->swaggerSpec['info']['title']}");
    $header .= $this->line($idn, "* {$this->swaggerSpec['info']['description']}");
    $header .= $this->line($idn, "*/");

    $header .= $this->line($idn, "namespace DoubleBreak\\Spapi\\Api;");
    $header .= $this->line($idn, "use DoubleBreak\\Spapi\\Client;");
    $header .= $this->line();
    $header .= $this->line($idn, "class {$this->className} extends Client {");

    return $header;
  }

  private function generateMethods($idn = '')
  {
    $methods = '';
    foreach ($this->parsedSpec as $spec) {
      $methods .= $this->generateDocComment($spec, $idn);
      $methods .= $this->generateMethod($spec, $idn);
    }
    return $methods;
  }

  private function generateDocComment($spec, $idn)
  {
    $docComment = $this->line();
    $docComment .= $this->line($idn, "/**");
    $docComment .= $this->line($idn, "* Operation {$spec['classMethod']}");

    if (count($spec['pathParams']) > 0) {
      $docComment .= $this->line($idn, '*');
    }
    foreach ($spec['pathParams'] as $param) {
      $n = $this->toCamel($param['name']);
      $d = str_replace("\n", "\n" . $idn . "*", $param['desc']);
      $docComment .= $this->line($idn, "* @param {$param['type']} \${$n} {$d}");
    }

    if (count($spec['queryParams'])>0) {
      $docComment .= $this->line($idn, '*');
      $docComment .= $this->line($idn, "* @param array \$queryParams");
      foreach ($spec['queryParams'] as $param) {
        $n = $this->toCamel($param['name']);
        $d = str_replace("\n", "\n" . $idn . "*", $param['desc']);
        $docComment .= $this->line($idn, "*    - *{$n}* {$param['type']} - {$d}");
      }
    }
    $docComment .= $this->line($idn, "*");
    $docComment .= $this->line($idn, "*/");

    return $docComment;
  }

  private function generateMethod($spec, $idn)
  {
    $methodSource = $this->generateMethodSignature($spec, $idn);
    $methodSource .= $this->line($idn, "{");
    $methodSource .= $this->generateMethodImplementation($spec, $idn . '  ');
    $methodSource .= $this->line($idn, '}');
    return $methodSource;
  }

  private function generateMethodSignature($spec, $idn)
  {
    $arguments = '';
    $c = '';

    foreach ($spec['pathParams'] as $param) {
      $arguments .= $c . '$' . $this->toCamel($param['name']);
      $c = ', ';
    }
    if (count($spec['queryParams']) > 0) {
      $arguments .= $c . '$queryParams = []';
      $c = ', ';
    }
    if (count($spec['bodyParams']) > 0) {
      $arguments .= $c . '$body = []';
    }
    $signature = $this->line($idn, "public function {$spec['classMethod']}({$arguments})");
    return $signature;
  }

  private function generateMethodImplementation($spec, $idn)
  {
    $path  = $spec['pathTemplate'];
    $httpMethod = strtoupper($spec['httpMethod']);
    foreach ($spec['pathParams'] as $p) {
      $n = $this->toCamel($p['name']);
      $path = str_replace('{'.$p['name'].'}', '{$'.$n.'}', $path);
    }
    $implementation = $this->line($idn, "return \$this->send(\"{$path}\", [");
    $implementation .= $this->line($idn, "  'method' => '{$httpMethod}',");

    if (count($spec['queryParams']) > 0) {
      $implementation .= $this->line($idn, "  'query' => \$queryParams,");
    }

    if (count($spec['bodyParams']) > 0) {
      $implementation .= $this->line($idn, "  'json' => \$body");
    }

    $implementation .= $this->line($idn, "]);");
    return $implementation;
  }

}




$specDir = $argv[1];
$allModels = scandir($specDir);



$allModels = array_slice($allModels, 2); //remove . and ..


foreach ($allModels as $model) {

  $modelFiles = scandir($specDir . '/' . $model);

  $generator = new SpapiGenerator(
    $specDir . '/' . $model . '/' . $modelFiles[2],
    $model,
    $modelFiles[2]
  );

  $generator->loadSpec();
  $generator->parseSpec();

  $source = $generator->generateCode();

  echo "Generating file " . __DIR__ . "/{$argv[2]}/{$generator->className}.php", PHP_EOL;
  file_put_contents(__DIR__ . "/{$argv[2]}/{$generator->className}.php", $source);
}

die();
//Run the generator
$generator = new SpapiGenerator($argv[1], $argv[2]);
$generator->loadSpec();
$generator->parseSpec();

$source = $generator->generateCode();
