<?php

namespace App\Integrations\SchoolRegistry\Exceptions;

/** The registry could not be reached, timed out, rate-limited us, kept failing (5xx), or is not configured. */
class RegistryUnavailable extends RegistryException {}
