<?php

namespace Dekor\Devio;

use RuntimeException;

/** Thrown by Console::fail(): the command stops, devio prints the message and exits 1. */
final class Failed extends RuntimeException
{
}
