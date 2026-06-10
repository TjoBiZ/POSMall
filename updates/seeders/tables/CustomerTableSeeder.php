<?php

declare(strict_types=1);

namespace KodZero\POSMall\Updates\Seeders\Tables;

use October\Rain\Database\Updates\Seeder;
use KodZero\POSMall\Classes\User\PosmallUserGroup;
use KodZero\POSMall\Models\Address;
use KodZero\POSMall\Models\Customer;
use KodZero\POSMall\Models\CustomerGroup;
use RainLab\Location\Models\Country;
use RainLab\Location\Models\State;
use RainLab\User\Models\User;
use Schema;

class CustomerTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * @param bool $useDemo
     * @return void
     */
    public function run(bool $useDemo = false)
    {
        if (!$useDemo && config('app.env') != 'testing') {
            return;
        }

        config()->set('rainlab.user::minPasswordLength', 8);

        $this->createUser(
            'normal_customer@example.tld',
            trans('kodzero.posmall::demo.customers.normal'),
        );

        $this->createUser(
            'gold_customer@example.tld',
            trans('kodzero.posmall::demo.customers.gold'),
            CustomerGroup::where('code', 'gold')->first()->id
        );

        $this->createUser(
            'diamond_customer@example.tld',
            trans('kodzero.posmall::demo.customers.diamond'),
            CustomerGroup::where('code', 'diamond')->first()->id
        );
    }

    /**
     * Create new user.
     * @param string $email
     * @param string $name
     * @param integer|null $customerGroupId
     * @return void
     */
    protected function createUser(string $email, string $name, ?int $customerGroupId = null)
    {
        [$firstname, $lastname] = explode(' ', $name);

        $fillable = (new User())->getFillable();
        $args = [
            'password' => '12345678',
        ];

        if (Schema::hasColumn('users', 'password_confirmation') && in_array('password_confirmation', $fillable, true)) {
            $args['password_confirmation'] = '12345678';
        }

        if (in_array('surname', $fillable)) {
            $args['name'] = $firstname;
            $args['surname'] = $lastname;
        } else {
            $args['first_name'] = $firstname;
            $args['last_name'] = $lastname;
        }

        $user = User::firstOrCreate([
            'email'                     => $email,
            'username'                  => $email,
        ], $args);

        $user->kodzero_posmall_customer_group_id = $customerGroupId;
        $user->save();
        PosmallUserGroup::attach($user);

        if ($user->wasRecentlyCreated) {
            PosmallUserGroup::markOwned($user);
        }

        $customer = Customer::firstOrCreate([
            'user_id'   => $user->id,
        ], [
            'firstname' => $firstname,
            'lastname'  => $lastname,
        ]);

        $shippingAddress = Address::firstOrCreate([
            'customer_id'   => $customer->id,
            'name'          => $name,
            'lines'         => 'Street 12',
            'zip'           => '6000',
            'city'          => 'Lucerne',
        ], [
            'name'          => $name,
            'lines'         => 'Street 12',
            'zip'           => '6000',
            'city'          => 'Lucerne',
            'state_id'      => State::where('name', 'Luzern')->first()->id,
            'country_id'    => Country::where('code', 'CH')->first()->id,
            'customer_id'   => $customer->id,
        ]);
        $customer->addresses()->save($shippingAddress);

        $billingAddress = Address::firstOrCreate([
            'customer_id'   => $customer->id,
            'name'          => $name,
            'lines'         => 'Street 12',
            'zip'           => '6000',
            'city'          => 'Lucerne',
        ], [
            'name'          => $name,
            'lines'         => 'Street 12',
            'zip'           => '6000',
            'city'          => 'Lucerne',
            'state_id'      => State::where('name', 'Luzern')->first()->id,
            'country_id'    => Country::where('code', 'CH')->first()->id,
            'customer_id'   => $customer->id,
        ]);
        $customer->addresses()->save($billingAddress);
    }
}
