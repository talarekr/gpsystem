<?php

namespace Tests\Unit;

use App\Models\PartCategory;
use App\Services\Marketplace\AllegroFunctionsBranchResolver;
use PHPUnit\Framework\TestCase;

class AllegroFunctionsBranchResolverTest extends TestCase
{
    public function test_null_category_does_not_match(): void
    {
        $this->assertFalse((new AllegroFunctionsBranchResolver())->matches(null));
    }

    public function test_target_parent_and_descendant_match_by_path_not_id(): void
    {
        $root = new PartCategory(['name' => ' Wyposażenie elektryczne ']);
        $target = new PartCategory(['name' => 'Przełączniki i przyciski']);
        $child = new PartCategory(['name' => 'Podświetlane']);
        $target->setRelation('parent', $root);
        $child->setRelation('parent', $target);

        $resolver = new AllegroFunctionsBranchResolver();
        $this->assertTrue($resolver->matches($target));
        $this->assertTrue($resolver->matches($child));
    }

    public function test_same_or_similar_name_in_other_branch_does_not_match(): void
    {
        $other = new PartCategory(['name' => 'Inna gałąź']);
        $duplicate = new PartCategory(['name' => 'Przełączniki i przyciski']);
        $similar = new PartCategory(['name' => 'Przełączniki oraz przyciski']);
        $duplicate->setRelation('parent', $other);
        $similar->setRelation('parent', new PartCategory(['name' => 'Wyposażenie elektryczne']));

        $resolver = new AllegroFunctionsBranchResolver();
        $this->assertFalse($resolver->matches($duplicate));
        $this->assertFalse($resolver->matches($similar));
    }
}
