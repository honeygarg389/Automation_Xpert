<?php

namespace Database\Factories;

use App\Modules\Shared\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'workspace_id' => 1,
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'phone_e164' => '+880'.$this->faker->numerify('1#########'),
            'email' => $this->faker->unique()->safeEmail(),
            'gender' => null,
            'birthday' => null,
            'anniversary_date' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'opt_in_whatsapp' => true,
            'opt_in_sms' => true,
            'opt_in_email' => true,
            'source' => 'import',
        ];
    }

    public function withProfile(): static
    {
        return $this->state(fn () => [
            'gender' => 'non_binary',
            'birthday' => '1990-05-20',
            'anniversary_date' => '2015-10-14',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'postal_code' => '560001',
        ]);
    }
}
