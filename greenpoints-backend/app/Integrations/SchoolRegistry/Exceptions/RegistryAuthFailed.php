<?php

namespace App\Integrations\SchoolRegistry\Exceptions;

/** The registry rejected our API key (HTTP 401/403). */
class RegistryAuthFailed extends RegistryException {}
