<?php
declare(strict_types = 1);

namespace LanguageServer\Tests\Server;

use LanguageServer\{
    DefinitionResolver, TreeAnalyzer
};
use LanguageServer\Index\{
    DependenciesIndex, Index, ProjectIndex
};
use function LanguageServer\pathToUri;
use Microsoft\PhpParser\Parser;
use PHPUnit\Framework\TestCase;
use phpDocumentor\Reflection\DocBlockFactory;

class IndexPerformanceTest extends TestCase
{
    /**
     * @var DefinitionResolver
     */
    private $definitionResolver;

    /**
     * @var Parser
     */
    private $parser;

    /**
     * @var DocBlockFactory
     */
    private $docBlockFactory;

    public function setUp()
    {
        $projectIndex = new ProjectIndex(new Index, new DependenciesIndex);
        $this->definitionResolver = new DefinitionResolver($projectIndex);
        $this->parser = new Parser();
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    public function testTreeAnalyzerPerformance()
    {
        $fileUri = pathToUri(__DIR__ . '/../../fixtures/index_performance.php');
        
        $start = microtime(true);
        $index = new Index;
        $treeAnalyzer = new TreeAnalyzer(
            $this->parser,
            \file_get_contents($fileUri),
            $this->docBlockFactory,
            $this->definitionResolver,
            $fileUri,
            $index
        );
        $elapsed = intval((microtime(true) - $start) * 1000);

        // Very generous this needs to be less than 1 second
        $this->assertLessThanOrEqual(1000, $elapsed);
    }
}
