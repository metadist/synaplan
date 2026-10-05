<?php

declare(strict_types=1);

namespace App\Tests\Integration\Stripe;

use App\Tests\Integration\Stripe\Mock\StripeParamShape;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPUnit\Framework\TestCase;

final class StripeParamShapeTest extends TestCase
{
    private const CHECKOUT_DOC = <<<'DOC'
        /**
         * @param null|array{allowed_payment_method_types?: string[], customer?: string, line_items?: array{price?: string, quantity?: int}[], metadata?: array<string, string>, mode?: string, subscription_data?: array{metadata?: array<string, string>, trial_period_days?: int}} $params
         * @param null|array|string $options
         */
        DOC;

    public function testRejectsAKeyTheSdkShapeNoLongerDeclares(): void
    {
        $unknown = StripeParamShape::unknownKeysForType($this->checkoutShape(), [
            'customer' => 'cus_1',
            'payment_method_types' => ['card', 'sepa_debit'],
            'mode' => 'subscription',
        ]);

        $this->assertSame(['payment_method_types'], $unknown);
    }

    public function testReportsUnknownKeysInsideListsAndNestedShapes(): void
    {
        $unknown = StripeParamShape::unknownKeysForType($this->checkoutShape(), [
            'line_items' => [['price' => 'price_1', 'quantity' => 1], ['price' => 'price_2', 'qty' => 2]],
            'subscription_data' => ['trial_days' => 14],
        ]);

        $this->assertSame(['line_items.1.qty', 'subscription_data.trial_days'], $unknown);
    }

    public function testAcceptsFreeFormMapsAndDeclaredKeys(): void
    {
        $unknown = StripeParamShape::unknownKeysForType($this->checkoutShape(), [
            'allowed_payment_method_types' => ['card'],
            'metadata' => ['user_id' => '42', 'anything' => 'goes'],
            'subscription_data' => ['metadata' => ['plan' => 'PRO']],
        ]);

        $this->assertSame([], $unknown);
    }

    public function testReadsTheShapeFromTheInstalledSdk(): void
    {
        $this->assertSame([], StripeParamShape::unknownKeys(\Stripe\Subscription::class, 'all', ['customer' => 'cus_1', 'status' => 'active']));
        $this->assertSame(['no_such_param'], StripeParamShape::unknownKeys(\Stripe\Subscription::class, 'all', ['no_such_param' => 1]));
    }

    private function checkoutShape(): TypeNode
    {
        $type = StripeParamShape::parseParamsType(self::CHECKOUT_DOC);
        $this->assertNotNull($type);

        return $type;
    }
}
