<?php

declare(strict_types=1);

namespace Storm\Telemetry\Tests\FailureRegistry;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Telemetry\FailureRegistry\FailureRegistryLint;

final class FailureRegistryLintTest extends TestCase
{
    #[Test]
    public function it_accepts_a_healthcheck_without_claiming_an_alert_rule(): void
    {
        $data = $this->valid();
        $data['classes'][0]['detection'] = ['state' => 'available', 'kind' => 'healthcheck', 'reference' => 'outbox_liveness'];
        self::assertSame([], (new FailureRegistryLint)->lint($data, []));

        $data['classes'][0]['resolution'] = ['state' => 'available', 'kind' => 'healthcheck', 'reference' => 'outbox_liveness', 'preconditions' => 'Read the outbox', 'expected_result' => 'Observe its state'];
        self::assertContains('broker.resolution.kind: unsupported reference kind.', (new FailureRegistryLint)->lint($data));
    }

    #[Test]
    public function it_refuses_an_empty_stage_without_hole_marker(): void
    {
        $errors = (new FailureRegistryLint)->lint(['classes' => [[
            'id' => 'broker-unavailable',
            'title' => 'Broker unavailable',
            'prevention' => [],
            'detection' => [],
            'resolution' => [],
            'rehearsal' => [],
        ]]]);

        self::assertContains('broker-unavailable.prevention: expected a stage with state available, hole or not_applicable.', $errors);
    }

    #[Test]
    public function it_refuses_a_hole_without_an_issue(): void
    {
        $data = $this->valid();
        unset($data['classes'][0]['detection']['issue']);
        self::assertContains('broker.detection.issue: expected a positive issue number.', (new FailureRegistryLint)->lint($data));
    }

    #[Test]
    public function it_refuses_an_unknown_stage_kind(): void
    {
        $data = $this->valid();
        $data['classes'][0]['detection'] = ['state' => 'available', 'kind' => 'shell', 'reference' => 'anything'];
        self::assertContains('broker.detection.kind: unsupported reference kind.', (new FailureRegistryLint)->lint($data));
    }

    #[Test]
    public function it_refuses_duplicate_class_ids(): void
    {
        $data = $this->valid();
        $data['classes'][] = $data['classes'][0];
        self::assertContains('broker.id: duplicate class identifier.', (new FailureRegistryLint)->lint($data));
    }

    #[Test]
    public function it_requires_exactly_four_stages(): void
    {
        $data = $this->valid();
        unset($data['classes'][0]['rehearsal']);
        $data['classes'][0]['execution'] = [];
        $errors = (new FailureRegistryLint)->lint($data);
        self::assertContains('broker.execution: unknown class field.', $errors);
        self::assertContains('broker.rehearsal: expected a stage with state available, hole or not_applicable.', $errors);
    }

    #[Test]
    public function it_reports_an_unknown_alert_rule_when_rules_are_provided(): void
    {
        $data = $this->valid();
        $data['classes'][0]['detection'] = ['state' => 'available', 'kind' => 'alert', 'reference' => 'BrokerDown'];
        $lint = new FailureRegistryLint;
        self::assertSame([], $lint->lint($data, ['BrokerDown']));
        self::assertSame([], $lint->lint($data));
        self::assertContains('broker.detection.reference: alert rule was not found in the supplied rules.', $lint->lint($data, []));
    }

    #[Test]
    public function it_accepts_a_referenced_manual_runbook_without_inventing_an_ops_verb(): void
    {
        $data = $this->valid();
        $data['classes'][0]['resolution'] = ['state' => 'available', 'kind' => 'runbook', 'reference' => 'Restore capacity', 'preconditions' => 'Identify the full volume', 'expected_result' => 'Writes resume'];
        self::assertSame([], (new FailureRegistryLint)->lint($data));
        $data['classes'][0]['resolution']['kind'] = 'console';
        $data['classes'][0]['resolution']['reference'] = 'messenger:failed:retry';
        self::assertSame([], (new FailureRegistryLint)->lint($data));
        $data['classes'][0]['resolution']['reference'] = '';
        self::assertContains('broker.resolution.reference: expected nonempty text.', (new FailureRegistryLint)->lint($data));
    }

    #[Test]
    public function it_requires_a_reason_and_decision_for_not_applicable(): void
    {
        $data = $this->valid();
        $data['classes'][0]['detection'] = ['state' => 'not_applicable'];
        self::assertCount(2, (new FailureRegistryLint)->lint($data));
        $data['classes'][0]['detection'] += ['reason' => 'No broker in this deployment', 'decision' => 'Decision #504'];
        self::assertSame([], (new FailureRegistryLint)->lint($data));
    }

    /**
     * @return mixed[]
     */
    private function valid(): array
    {
        return ['classes' => [[
            'id' => 'broker', 'title' => 'Broker unavailable',
            'prevention' => ['state' => 'hole', 'issue' => 504],
            'detection' => ['state' => 'hole', 'issue' => 504],
            'resolution' => ['state' => 'hole', 'issue' => 504],
            'rehearsal' => ['state' => 'hole', 'issue' => 504],
        ]]];
    }
}
