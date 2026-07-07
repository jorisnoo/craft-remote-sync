<?php

namespace Noo\CraftRemoteSync\Sync;

enum Direction: string
{
    case Pull = 'pull';
    case Push = 'push';
}
