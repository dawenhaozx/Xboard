<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AuthService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    public function getActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $authService = new AuthService($user);
        return $this->success($authService->getSessions());
    }

    public function removeActiveSession(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $authService = new AuthService($user);
        return $this->success($authService->removeSession($request->input('session_id')));
    }

    public function checkLogin(Request $request)
    {
        $data = [
            'is_login' => $request->user['id'] ? true : false
        ];
        if ($request->user['is_admin']) {
            $data['is_admin'] = true;
        }
        return $this->success($data);
    }

    public function changePassword(UserChangePassword $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $request->input('old_password'),
            $user->password)
        ) {
            return $this->fail([400, __('The old password is wrong')]);
        }
        $user->password = password_hash($request->input('new_password'), PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;
        if (!$user->save()) {
            return $this->fail([400, __('Save failed')]);
        }
        return $this->success(true);
    }

    public function info(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'email',
                'transfer_enable',
                'device_limit',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user['avatar_url'] = 'https://cdn.v2ex.com/gravatar/' . md5($user->email) . '?s=64&d=identicon';
        return $this->success($user);
    }

    public function getStat(Request $request)
    {
        $stat = [
            Order::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            Ticket::where('status', 0)
                ->where('user_id', $request->user['id'])
                ->count(),
            User::where('invite_user_id', $request->user['id'])
                ->count()
        ];
        return $this->success($stat);
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user['id'])
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'device_limit',
                'email',
                'uuid'
            ])
            ->first();
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                return $this->fail([400, __('Subscription plan does not exist')]);
            }
        }
        // 统计在线设备
        // $cacheKey = 'ALIVE_IP_USER_' . $user['id'];
        // $user['alive_ip'] = Cache::get($cacheKey)['alive_ip'] ?? 0;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        return $this->success($user);
    }

    public function resetSecurity(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->fail([400, __('Reset failed')]);
        }
        return $this->success(Helper::getSubscribeUrl($user->token));
    }

    public function update(UserUpdate $request)
    {
        $updateData = $request->only([
            'remind_expire',
            'remind_traffic'
        ]);

        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        try {
            $user->update($updateData);
        } catch (\Exception $e) {
            return $this->fail([400, __('Save failed')]);
        }

        return $this->success(true);
    }

    public function transfer(UserTransfer $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }
        if ($request->input('transfer_amount') > $user->commission_balance) {
            return $this->fail([400, __('Insufficient commission balance')]);
        }
        $user->commission_balance = $user->commission_balance - $request->input('transfer_amount');
        $user->balance = $user->balance + $request->input('transfer_amount');
        if (!$user->save()) {
            return $this->fail([400, __('Transfer failed')]);
        }
        return $this->success(true);
    }

    public function getQuickLoginUrl(Request $request)
    {
        $user = User::find($request->user['id']);
        if (!$user) {
            return $this->fail([400, __('The user does not exist')]);
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return $this->success($url);
    }
}
