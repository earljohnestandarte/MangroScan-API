<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::withTrashed()->find(RbacSeedData::ORGANIZATION_ID) ?? new Organization;
        $organization->organization_id = RbacSeedData::ORGANIZATION_ID;
        $organization->fill([
            'organization_name' => 'MangroScan Development Organization',
            'organization_type' => 'development',
            'contact_email' => 'development@mangroscan.test',
            'status' => 'active',
        ]);
        $organization->deleted_at = null;
        $organization->save();
    }
}
