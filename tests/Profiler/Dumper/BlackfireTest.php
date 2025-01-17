<?php

namespace Twig\Tests\Profiler\Dumper;

/*
 * This file is part of Twig.
 *
 * (c) Fabien Potencier
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Twig\Profiler\Dumper\BlackfireDumper;

class BlackfireTest extends ProfilerTestCase
{
    public function testDump()
    {
        $dumper = new BlackfireDumper();

        $this->assertStringMatchesFormat(<<<EOF
file-format: BlackfireProbe
cost-dimensions: wt mu pmu
request-start: %d.%d

main()//1 %d %d %d
main()==>index.twig//1 %d %d %d
index.twig==>embedded.twig::block(body)//1 %d %d 0
index.twig==>embedded.twig//2 %d %d %d
embedded.twig==>included.twig//2 %d %d %d
index.twig==>index.twig::macro(foo)//1 %d %d %d
EOF
            , $dumper->dump($this->getProfile()));
    }
}
