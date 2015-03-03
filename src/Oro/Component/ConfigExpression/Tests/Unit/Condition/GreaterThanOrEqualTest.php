<?php

namespace Oro\Component\ConfigExpression\Tests\Unit\Condition;

use Oro\Component\ConfigExpression\ContextAccessor;
use Oro\Component\ConfigExpression\Condition;
use Oro\Component\ConfigExpression\PropertyAccess\PropertyPath;

class GreaterThanOrEqualTest extends \PHPUnit_Framework_TestCase
{
    /** @var Condition\GreaterThanOrEqual */
    protected $condition;

    protected function setUp()
    {
        $this->condition = new Condition\GreaterThanOrEqual();
        $this->condition->setContextAccessor(new ContextAccessor());
    }

    /**
     * @dataProvider evaluateDataProvider
     */
    public function testEvaluate(array $options, $context, $expectedResult)
    {
        $this->assertSame($this->condition, $this->condition->initialize($options));
        $this->assertEquals($expectedResult, $this->condition->evaluate($context));
    }

    public function evaluateDataProvider()
    {
        $options = ['left' => new PropertyPath('foo'), 'right' => new PropertyPath('bar')];

        return [
            'greater_than' => [
                'options'        => $options,
                'context'        => ['foo' => 100, 'bar' => 50],
                'expectedResult' => true
            ],
            'equal'        => [
                'options'        => $options,
                'context'        => ['foo' => 50, 'bar' => 50],
                'expectedResult' => true
            ],
            'less_than'    => [
                'options'        => $options,
                'context'        => ['foo' => 50, 'bar' => 100],
                'expectedResult' => false
            ]
        ];
    }

    /**
     * @dataProvider toArrayDataProvider
     */
    public function testToArray($options, $message, $expected)
    {
        $this->condition->initialize($options);
        if ($message !== null) {
            $this->condition->setMessage($message);
        }
        $actual = $this->condition->toArray();
        $this->assertEquals($expected, $actual);
    }

    public function toArrayDataProvider()
    {
        return [
            [
                'options'  => ['left', 'right'],
                'message'  => null,
                'expected' => [
                    '@gte' => [
                        'parameters' => [
                            'left',
                            'right'
                        ]
                    ]
                ]
            ],
            [
                'options'  => ['left', 'right'],
                'message'  => 'Test',
                'expected' => [
                    '@gte' => [
                        'message'    => 'Test',
                        'parameters' => [
                            'left',
                            'right'
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * @dataProvider compileDataProvider
     */
    public function testCompile($options, $message, $expected)
    {
        $this->condition->initialize($options);
        if ($message !== null) {
            $this->condition->setMessage($message);
        }
        $actual = $this->condition->compile('$factory');
        $this->assertEquals($expected, $actual);
    }

    public function compileDataProvider()
    {
        return [
            [
                'options'  => [new PropertyPath('foo'), 123],
                'message'  => null,
                'expected' => '$factory->create(\'gte\', ['
                    . 'new \Oro\Component\ConfigExpression\CompiledPropertyPath(\'foo\', [\'foo\'])'
                    . ', 123])'
            ],
            [
                'options'  => [new PropertyPath('foo'), 123],
                'message'  => 'Test',
                'expected' => '$factory->create(\'gte\', ['
                    . 'new \Oro\Component\ConfigExpression\CompiledPropertyPath(\'foo\', [\'foo\'])'
                    . ', 123])->setMessage(\'Test\')'
            ]
        ];
    }
}
