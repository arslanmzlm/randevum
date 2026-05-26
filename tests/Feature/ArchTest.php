<?php

arch('debugging helpers are not left in the codebase')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
