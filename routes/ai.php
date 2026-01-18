<?php

use App\Mcp\Servers\AppServer;
use Laravel\Mcp\Facades\Mcp;

// Register local MCP server for Cursor integration
Mcp::local('app', AppServer::class);

// Mcp::web('/mcp/demo', \App\Mcp\Servers\PublicServer::class);
