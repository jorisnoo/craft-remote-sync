<?php

arch('no debugging statements are committed')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
