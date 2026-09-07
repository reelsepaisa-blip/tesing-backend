@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ isset($role) ? 'Edit Role' : 'Create Role' }}</h3>
                </div>
                <div class="card-body">
                    <form action="{{ isset($role) ? route('admin.roles.update', $role->id) : route('admin.roles.store') }}" method="POST">
                        @csrf
                        @if(isset($role))
                            @method('PUT')
                        @endif

                        <div class="form-group">
                            <label for="name">Role Name</label>
                            <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" 
                                value="{{ old('name', isset($role) ? $role->name : '') }}" required>
                            @error('name')
                                <span class="invalid-feedback">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="form-group">
                            <label>Permissions</label>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Module</th>
                                            <th>Create</th>
                                            <th>Edit</th>
                                            <th>View</th>
                                            <th>Delete</th>
                                            <th>All</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($modules as $module => $actions)
                                        <tr>
                                            <td>{{ ucfirst($module) }}</td>
                                            @foreach($actions as $action)
                                            <td>
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" 
                                                        class="custom-control-input permission-toggle" 
                                                        id="{{ $module }}_{{ $action }}"
                                                        name="permissions[{{ $module }}][{{ $action }}]"
                                                        data-module="{{ $module }}"
                                                        {{ isset($role) && $role->hasPermissionTo("$module.$action") ? 'checked' : '' }}>
                                                    <label class="custom-control-label" for="{{ $module }}_{{ $action }}"></label>
                                                </div>
                                            </td>
                                            @endforeach
                                            <td>
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" 
                                                        class="custom-control-input all-permission" 
                                                        id="{{ $module }}_all"
                                                        data-module="{{ $module }}">
                                                    <label class="custom-control-label" for="{{ $module }}_all"></label>
                                                </div>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                {{ isset($role) ? 'Update Role' : 'Create Role' }}
                            </button>
                            <a href="{{ route('admin.roles.index') }}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    $(document).ready(function() {
        // Handle "All" permission toggle
        $('.all-permission').change(function() {
            const module = $(this).data('module');
            const isChecked = $(this).prop('checked');
            
            // Toggle all permissions for this module
            $(`input[data-module="${module}"].permission-toggle`).prop('checked', isChecked);
        });

        // Handle individual permission toggles
        $('.permission-toggle').change(function() {
            const module = $(this).data('module');
            const allChecked = $(`input[data-module="${module}"].permission-toggle`).toArray()
                .every(input => $(input).prop('checked'));
            
            // Update "All" checkbox based on individual permissions
            $(`#${module}_all`).prop('checked', allChecked);
        });

        // Initial check of "All" checkboxes
        $('.all-permission').each(function() {
            const module = $(this).data('module');
            const allChecked = $(`input[data-module="${module}"].permission-toggle`).toArray()
                .every(input => $(input).prop('checked'));
            $(this).prop('checked', allChecked);
        });
    });
</script>
@endpush
@endsection