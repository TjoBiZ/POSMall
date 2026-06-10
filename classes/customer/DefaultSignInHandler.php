<?php

namespace KodZero\POSMall\Classes\Customer;

use Auth;
use Event;
use Exception;
use Flash;
use October\Rain\Auth\AuthException;
use October\Rain\Exception\ValidationException;
use KodZero\POSMall\Classes\User\PosmallUserGroup;
use KodZero\POSMall\Classes\User\Settings;
use KodZero\POSMall\Models\Cart;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\Wishlist;
use RainLab\User\Models\Setting;
use RainLab\User\Models\User;
use RainLab\User\Models\UserLog;
use Validator;

class DefaultSignInHandler implements SignInHandler
{
    public function handle(array $data): ?User
    {
        try {
            return $this->login($data);
        } catch (ValidationException $ex) {
            throw $ex;
        } catch (AuthException $ex) {
            $error = str_contains($ex->getMessage(), 'not activated')
                ? 'not_activated'
                : 'unknown_user';

            Flash::error(trans('kodzero.posmall::lang.components.signup.errors.' . $error));
        } catch (Exception $ex) {
            Flash::error($ex->getMessage());
        }

        return null;
    }

    /**
     * @throws AuthException
     * @throws ValidationException
     */
    protected function login(array $data)
    {
        $this->validate($data);

        $credentials = [
            'login'    => array_get($data, 'login'),
            'password' => array_get($data, 'password'),
        ];

        Event::fire('rainlab.user.beforeAuthenticate', [$this, $credentials]);
        Event::fire('posmall.customer.beforeAuthenticate', [$this, $credentials]);

        // RainLab.User 3.0 compatibility
        if (class_exists(Setting::class)) {
            if (Auth::attempt(['email' => $credentials['login'], 'password' => $credentials['password']], true)) {
                $user = Auth::user();
            } else {
                throw new AuthException('rainlab.user::lang.account.invalid_login');
            }
        } else {
            $user = Auth::authenticate($credentials, true);
        }

        if (method_exists($user, 'isBanned') && $user->isBanned()) {
            Auth::logout();

            throw new AuthException('rainlab.user::lang.account.banned');
        }

        $customer = Customer::forUser($user);

        // If the user doesn't have a Customer model it was created via the backend.
        // Make sure to add the Customer model now
        if (! $customer && ! $user->is_guest) {
            $customer = new Customer();

            // RainLab.User 3.0 compatibility
            if (class_exists(Setting::class)) {
                $customer->firstname = $user->first_name;
                $customer->lastname  = $user->last_name;
            } else {
                $customer->firstname = $user->name;
                $customer->lastname  = $user->surname;
            }

            $customer->user_id   = $user->id;
            $customer->is_guest  = false;
            $customer->save();
        }

        PosmallUserGroup::attach($user);

        if ($customer && $customer->is_guest) {
            Auth::logout();

            throw new AuthException('kodzero.posmall::lang.components.signup.errors.user_is_guest');
        }

        if ($customer) {
            Cart::transferSessionCartToCustomer($customer);
            Wishlist::transferToCustomer($customer);
        }

        if (class_exists(UserLog::class)) {
            UserLog::createRecord($user->getKey(), UserLog::TYPE_SELF_LOGIN, [
                'user_full_name' => $user->full_name,
                'is_two_factor' => false,
            ]);
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    protected function validate(array $data)
    {
        $minPasswordLength = Settings::getMinPasswordLength();
        $rules    = [
            'login'    => 'required|email|between:6,255',
            'password' => sprintf('required|min:%d|max:255', $minPasswordLength),
        ];
        $messages = [
            'login.required'    => trans('kodzero.posmall::lang.components.signup.errors.login.required'),
            'login.email'       => trans('kodzero.posmall::lang.components.signup.errors.login.email'),
            'login.between'     => trans('kodzero.posmall::lang.components.signup.errors.login.between'),
            'password.required' => trans('kodzero.posmall::lang.components.signup.errors.password.required'),
            'password.max'      => trans('kodzero.posmall::lang.components.signup.errors.password.max'),
        ];

        $validation = Validator::make($data, $rules, $messages);

        if ($validation->fails()) {
            throw new ValidationException($validation);
        }
    }
}
