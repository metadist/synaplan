<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware\Eval;

use App\Service\SelfAware\Eval\SelfAwareEvalAssertions;
use App\Service\SelfAware\Eval\SelfAwareEvalCorpus;
use PHPUnit\Framework\TestCase;

final class SelfAwareEvalCorpusTest extends TestCase
{
    public function testCorpusLoadsAndOnlyFilterWorks(): void
    {
        $path = dirname(__DIR__, 4).'/Eval/self_aware_eval_corpus.json';
        $rows = SelfAwareEvalCorpus::load($path);
        $this->assertNotEmpty($rows);

        $only = SelfAwareEvalCorpus::select($rows, 'Q1,Q26', null);
        $this->assertCount(2, $only);
        $this->assertSame(['Q1', 'Q26'], array_column($only, 'id'));

        $install = SelfAwareEvalCorpus::select($rows, null, 'no_keys');
        foreach ($install as $row) {
            $this->assertContains($row['install'], ['no_keys', 'any']);
        }
    }

    public function testAssertions(): void
    {
        $this->assertTrue(SelfAwareEvalAssertions::topicMatches('synaplan', ['topic' => 'synaplan']));
        $this->assertTrue(SelfAwareEvalAssertions::topicMatches('general', ['topic' => 'not_synaplan']));
        $this->assertFalse(SelfAwareEvalAssertions::topicMatches('synaplan', ['topic' => 'not_synaplan']));

        $this->assertSame(
            [],
            SelfAwareEvalAssertions::answerFailures('PDF is not available. I can write a DOCX.', [
                'must_contain_any' => ['not', 'nicht'],
                'must_mention_any' => ['DOCX', 'Word'],
                'must_not_contain' => ['€', '$'],
            ]),
        );
        $this->assertNotEmpty(SelfAwareEvalAssertions::answerFailures('Here is a €9 plan', [
            'must_not_contain' => ['€'],
        ]));
    }
}
