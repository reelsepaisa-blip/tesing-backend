<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    protected $modules = [
        'booking' => ['create', 'edit', 'view', 'delete'],
        'users' => ['create', 'edit', 'view', 'delete'],
        'drivers' => ['create', 'edit', 'view', 'delete'],
        'vehicles' => ['create', 'edit', 'view', 'delete'],
        'payments' => ['create', 'edit', 'view', 'delete']
    ];

    public function __construct()
    {
        $this->middleware(['auth', 'permission:roles.manage']);
    }

    public function index()
    {
        $roles = Role::with('permissions')->get();
        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        $modules = $this->modules;
        return view('admin.roles.form', compact('modules'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|unique:roles,name',
            'permissions' => 'required|array',
        ]);

        DB::beginTransaction();
        try {
            $role = Role::create(['name' => $request->name]);
            
            foreach ($request->permissions as $module => $actions) {
                foreach ($actions as $action => $value) {
                    if ($value === 'on') {
                        $permissionName = "$module.$action";

                        Permission::firstOrCreate(['name' => $permissionName]);
                        $role->givePermissionTo($permissionName);
                    }
                }
            }
            
            DB::commit();
            return redirect()->route('admin.roles.index')->with('success', 'Role created successfully');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'Error creating role: ' . $e->getMessage());
        }
    }

    public function edit(Role $role)
    {
        $modules = $this->modules;
        return view('admin.roles.form', compact('role', 'modules'));
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => 'required|unique:roles,name,' . $role->id,
            'permissions' => 'required|array',
        ]);

        DB::beginTransaction();
        try {
            $role->update(['name' => $request->name]);

            $role->syncPermissions([]);
            
            foreach ($request->permissions as $module => $actions) {
                foreach ($actions as $action => $value) {
                    if ($value === 'on') {
                        $permissionName = "$module.$action";

                        Permission::firstOrCreate(['name' => $permissionName]);
                        $role->givePermissionTo($permissionName);
                    }
                }
            }
            
            DB::commit();
            return redirect()->route('admin.roles.index')->with('success', 'Role updated successfully');
        } catch (\Exception $e) {
            DB::rollback();
            return back()->with('error', 'Error updating role: ' . $e->getMessage());
        }
    }

    public function destroy(Role $role)
    {
        try {
            $role->delete();
            return redirect()->route('admin.roles.index')->with('success', 'Role deleted successfully');
        } catch (\Exception $e) {
            return back()->with('error', 'Error deleting role: ' . $e->getMessage());
        }
    }
}
