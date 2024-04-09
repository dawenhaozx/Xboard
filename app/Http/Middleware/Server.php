<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\ServerService;
use Closure;
use Illuminate\Http\Request;

class Server
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // alias
        $aliasTypes = [
            'v2ray' => 'vmess',
            'hysteria2' => 'hysteria'
        ];
        $request->validate([
            'token' => ['required', 'string', 'in:' . admin_setting('server_token')],
            'node_id' => 'required',
            'node_type' => [
                'regex:/^(?i)(hysteria|hysteria2|vless|trojan|vmess|v2ray|tuic|shadowsocks|shadowsocks-plugin)$/',
                function ($attribute, $value, $fail) use ($aliasTypes, $request) {
                    $request->merge([$attribute => strtolower(isset ($aliasTypes[$value]) ? $aliasTypes[$value] : $value)]);
                },
            ]
        ], [
            'token.in' => 'Token is error!',
            'node_type.regex' => 'node_type is error!'
        ]);
        $nodeInfo = ServerService::getServer($request->input('node_id'), $request->input('node_type'));
        if (!$nodeInfo)
            throw new ApiException('server is not exist!');
        $request->merge(['node_info' => $nodeInfo]);
        return $next($request);
    }
}
