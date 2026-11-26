<?php
// templates/hrTemplates/employee_modals.php
?>
<style>
  .emp-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.78);
    backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 18px;
    overflow-y: auto;
  }
  .emp-modal-backdrop.is-open {
    display: flex;
  }
  .emp-modal-dialog {
    width: 100%;
    max-width: 780px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    background: #0d081a;
    border: 1px solid rgba(139, 92, 246, 0.35);
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.65);
    color: #f8fafc;
    overflow: hidden;
  }
  .emp-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 22px;
    background: rgba(255, 255, 255, 0.03);
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
  }
  .emp-modal-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #fff;
  }
  .emp-modal-close {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    background: rgba(255, 255, 255, 0.05);
    color: #e2e8f0;
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
  }
  .emp-modal-close:hover {
    background: rgba(239, 68, 68, 0.22);
    border-color: rgba(248, 113, 113, 0.45);
    color: #fff;
  }
  .emp-modal-body {
    padding: 20px 22px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 18px;
  }
  .emp-modal-banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 16px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(148, 163, 184, 0.16);
  }
  .emp-modal-section-title {
    margin: 0 0 8px;
    font-size: 14px;
    font-weight: 700;
    color: #e2e8f0;
  }
  .emp-modal-list {
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background: rgba(15, 10, 30, 0.75);
    overflow: hidden;
  }
  .emp-modal-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.1);
    font-size: 13.5px;
  }
  .emp-modal-row:last-child {
    border-bottom: none;
  }
  .emp-modal-row strong {
    color: #e2e8f0;
    min-width: 150px;
  }
  .emp-modal-row span {
    color: #cbd5e1;
    word-break: break-word;
  }
  .emp-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
  }
  .emp-field {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }
  .emp-field.full {
    grid-column: 1 / -1;
  }
  .emp-field label {
    font-size: 12px;
    font-weight: 600;
    color: #cbd5e1;
  }
  .emp-input, .emp-select, .emp-textarea {
    width: 100%;
    padding: 9px 11px;
    border-radius: 9px;
    border: 1px solid rgba(148, 163, 184, 0.24);
    background: #140e26;
    color: #f8fafc;
    font-size: 13px;
    box-sizing: border-box;
  }
  .emp-input:focus, .emp-select:focus, .emp-textarea:focus {
    outline: none;
    border-color: #8b5cf6;
  }
  .emp-modal-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 22px;
    background: rgba(255, 255, 255, 0.02);
    border-top: 1px solid rgba(148, 163, 184, 0.16);
  }
  .emp-btn {
    padding: 8px 14px;
    border-radius: 9px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    background: rgba(255, 255, 255, 0.06);
    color: #f8fafc;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
  }
  .emp-btn:hover {
    background: rgba(255, 255, 255, 0.12);
  }
  .emp-btn-primary {
    background: #7c3aed;
    border-color: #7c3aed;
    color: #fff;
  }
  .emp-btn-primary:hover {
    background: #6d28d9;
  }
  .emp-btn-danger {
    background: rgba(239, 68, 68, 0.16);
    border-color: rgba(248, 113, 113, 0.45);
    color: #fca5a5;
  }
  .emp-badge {
    padding: 3px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    background: rgba(139, 92, 246, 0.22);
    color: #ddd6fe;
  }
  .emp-modal-msg {
    padding: 10px 12px;
    border-radius: 9px;
    font-size: 13px;
    display: none;
  }
  .emp-modal-msg.err {
    display: block;
    background: rgba(239, 68, 68, 0.18);
    border: 1px solid rgba(248, 113, 113, 0.45);
    color: #fecaca;
  }
  .emp-modal-msg.ok {
    display: block;
    background: rgba(34, 197, 94, 0.18);
    border: 1px solid rgba(74, 222, 128, 0.45);
    color: #bbf7d0;
  }
</style>

<!-- VIEW EMPLOYEE MODAL -->
<div class="emp-modal-backdrop" id="viewEmployeeModal" role="dialog" aria-modal="true" aria-labelledby="viewEmpModalTitle">
  <div class="emp-modal-dialog">
    <div class="emp-modal-header">
      <h2 class="emp-modal-title" id="viewEmpModalTitle">Employee Profile</h2>
      <button type="button" class="emp-modal-close" data-close-modal="viewEmployeeModal" aria-label="Close">&times;</button>
    </div>
    <div class="emp-modal-body">
      <div class="emp-modal-msg" id="viewEmpError"></div>
      <div class="emp-modal-banner">
        <div>
          <strong id="v_code_name">Loading...</strong>
          <em style="color:#94a3b8;margin-left:6px;" id="v_status_inline"></em>
        </div>
        <span class="emp-badge" id="v_rank_badge">Employee</span>
      </div>

      <div>
        <h3 class="emp-modal-section-title">Personal Information</h3>
        <div class="emp-modal-list">
          <div class="emp-modal-row"><strong>Fullname:</strong><span id="v_fullname">N/A</span></div>
          <div class="emp-modal-row"><strong>Date of Birth:</strong><span id="v_dob">N/A</span></div>
          <div class="emp-modal-row"><strong>Gender:</strong><span id="v_gender">N/A</span></div>
          <div class="emp-modal-row"><strong>Phone:</strong><span id="v_phone">N/A</span></div>
          <div class="emp-modal-row"><strong>Email:</strong><span id="v_email">N/A</span></div>
          <div class="emp-modal-row"><strong>Address:</strong><span id="v_address">N/A</span></div>
        </div>
      </div>

      <div>
        <h3 class="emp-modal-section-title">Employment Information</h3>
        <div class="emp-modal-list">
          <div class="emp-modal-row"><strong>Department:</strong><span id="v_department">N/A</span></div>
          <div class="emp-modal-row"><strong>Position:</strong><span id="v_position">N/A</span></div>
          <div class="emp-modal-row"><strong>Rank:</strong><span id="v_rank">N/A</span></div>
          <div class="emp-modal-row"><strong>Date Hired:</strong><span id="v_hired">N/A</span></div>
          <div class="emp-modal-row"><strong>Employment Type:</strong><span id="v_emp_type">N/A</span></div>
          <div class="emp-modal-row"><strong>Status:</strong><span id="v_emp_status">N/A</span></div>
        </div>
      </div>
    </div>
    <div class="emp-modal-footer">
      <form id="v_deactivate_form" action="/hr/employee_deactivate.php" method="POST" onsubmit="return confirm('Mark this employee as Inactive?');" style="display:inline;margin-right:auto;">
        <input type="hidden" name="id" id="v_deactivate_id" value="">
        <button type="submit" class="emp-btn emp-btn-danger">Deactivate This Employee?</button>
      </form>
      <button type="button" id="v_switch_edit_btn" class="emp-btn emp-btn-primary">Edit</button>
      <button type="button" class="emp-btn" data-close-modal="viewEmployeeModal">Close</button>
    </div>
  </div>
</div>

<!-- EDIT EMPLOYEE MODAL -->
<div class="emp-modal-backdrop" id="editEmployeeModal" role="dialog" aria-modal="true" aria-labelledby="editEmpModalTitle">
  <div class="emp-modal-dialog">
    <div class="emp-modal-header">
      <h2 class="emp-modal-title" id="editEmpModalTitle">Edit Employee</h2>
      <button type="button" class="emp-modal-close" data-close-modal="editEmployeeModal" aria-label="Close">&times;</button>
    </div>
    <form id="editEmployeeForm" method="post">
      <div class="emp-modal-body">
        <div class="emp-modal-msg" id="editEmpMessage"></div>
        <input type="hidden" name="id" id="e_id">

        <div>
          <h3 class="emp-modal-section-title">Personal Information</h3>
          <div class="emp-form-grid">
            <div class="emp-field">
              <label for="e_employee_id">Employee ID *</label>
              <input type="text" name="employee_id" id="e_employee_id" class="emp-input" required>
            </div>
            <div class="emp-field">
              <label for="e_first_name">First Name *</label>
              <input type="text" name="first_name" id="e_first_name" class="emp-input" required>
            </div>
            <div class="emp-field">
              <label for="e_middle_name">Middle Name</label>
              <input type="text" name="middle_name" id="e_middle_name" class="emp-input">
            </div>
            <div class="emp-field">
              <label for="e_last_name">Last Name *</label>
              <input type="text" name="last_name" id="e_last_name" class="emp-input" required>
            </div>
            <div class="emp-field">
              <label for="e_suffix">Suffix</label>
              <input type="text" name="suffix" id="e_suffix" class="emp-input">
            </div>
            <div class="emp-field">
              <label for="e_date_of_birth">Date of Birth</label>
              <input type="date" name="date_of_birth" id="e_date_of_birth" class="emp-input">
            </div>
            <div class="emp-field">
              <label for="e_gender">Gender</label>
              <select name="gender" id="e_gender" class="emp-select">
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="emp-field">
              <label for="e_phone">Phone Number</label>
              <input type="text" name="phone" id="e_phone" class="emp-input">
            </div>
            <div class="emp-field full">
              <label for="e_email">Email</label>
              <input type="text" name="email" id="e_email" class="emp-input">
            </div>
            <div class="emp-field full">
              <label for="e_address">Address</label>
              <textarea name="address" id="e_address" rows="2" class="emp-textarea"></textarea>
            </div>
          </div>
        </div>

        <div>
          <h3 class="emp-modal-section-title">Employment Information</h3>
          <div class="emp-form-grid">
            <div class="emp-field">
              <label for="e_department_id">Department</label>
              <select name="department_id" id="e_department_id" class="emp-select"></select>
            </div>
            <div class="emp-field">
              <label for="e_position_id">Position</label>
              <select name="position_id" id="e_position_id" class="emp-select"></select>
            </div>
            <div class="emp-field">
              <label for="e_rank">Position Rank</label>
              <select name="rank" id="e_rank" class="emp-select">
                <option value="employee">Employee</option>
                <option value="team_leader">Team Leader</option>
                <option value="manager">Department Manager</option>
              </select>
            </div>
            <div class="emp-field">
              <label for="e_manager_id">Reports to (Manager)</label>
              <select name="manager_id" id="e_manager_id" class="emp-select"></select>
            </div>
            <div class="emp-field">
              <label for="e_date_hired">Date Hired *</label>
              <input type="date" name="date_hired" id="e_date_hired" class="emp-input" required>
            </div>
            <div class="emp-field">
              <label for="e_employment_type">Employment Type</label>
              <select name="employment_type" id="e_employment_type" class="emp-select">
                <option value="Full-time">Full-time</option>
                <option value="Part-time">Part-time</option>
                <option value="Contract">Contract</option>
                <option value="Intern">Intern</option>
              </select>
            </div>
            <div class="emp-field">
              <label for="e_employment_status">Employment Status</label>
              <select name="employment_status" id="e_employment_status" class="emp-select">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
                <option value="On Leave">On Leave</option>
                <option value="Suspended">Suspended</option>
              </select>
            </div>
          </div>
        </div>
      </div>
      <div class="emp-modal-footer">
        <button type="button" class="emp-btn" data-close-modal="editEmployeeModal">Cancel</button>
        <button type="submit" id="editEmployeeSaveBtn" class="emp-btn emp-btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
  let activeEmpId = null;

  function byId(id) {
    return document.getElementById(id);
  }

  function setMsg(el, type, text) {
    if (!el) return;
    el.className = 'emp-modal-msg';
    el.textContent = '';
    if (!text) return;
    el.classList.add(type === 'ok' ? 'ok' : 'err');
    el.textContent = text;
  }

  function openModal(modalId) {
    const el = byId(modalId);
    if (!el) return;
    el.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal(modalId) {
    const el = byId(modalId);
    if (!el) return;
    el.classList.remove('is-open');
    if (!document.querySelector('.emp-modal-backdrop.is-open')) {
      document.body.style.overflow = '';
    }
  }

  function valOrNA(v) {
    return (v !== null && v !== undefined && String(v).trim() !== '') ? String(v) : 'N/A';
  }

  function formatDate(d) {
    if (!d) return 'N/A';
    const dt = new Date(d + 'T00:00:00');
    return Number.isNaN(dt.getTime()) ? d : dt.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function formatRank(r) {
    const map = { employee: 'Employee', team_leader: 'Team Leader', manager: 'Department Manager' };
    return map[r] || r || 'Employee';
  }

  function populateSelect(sel, items, selectedVal, defaultLabel, labelFn) {
    if (!sel) return;
    sel.innerHTML = `<option value="">${defaultLabel}</option>`;
    (items || []).forEach(item => {
      const opt = document.createElement('option');
      opt.value = String(item.id);
      opt.textContent = labelFn(item);
      if (String(item.id) === String(selectedVal ?? '')) {
        opt.selected = true;
      }
      sel.appendChild(opt);
    });
  }

  async function fetchEmployeeData(id) {
    const res = await fetch(`/hr/view_employee.php?ajax=1&id=${encodeURIComponent(id)}`, {
      cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    if (!res.ok || !data.ok) {
      throw new Error(data.error || 'Failed to load employee details.');
    }
    return data;
  }

  window.openEmployeeViewModal = async function (id) {
    activeEmpId = Number(id);
    setMsg(byId('viewEmpError'), '', '');
    byId('v_code_name').textContent = 'Loading...';
    byId('v_status_inline').textContent = '';
    openModal('viewEmployeeModal');

    try {
      const { employee: emp } = await fetchEmployeeData(id);
      const fullName = [emp.first_name, emp.middle_name, emp.last_name, emp.suffix]
        .filter(v => v && String(v).trim() !== '')
        .join(' ');

      byId('v_code_name').textContent = `${emp.employee_id || ''} ${fullName}`.trim();
      byId('v_status_inline').textContent = emp.employment_status || '';
      byId('v_rank_badge').textContent = emp.rank_label || formatRank(emp.rank);

      byId('v_fullname').textContent = valOrNA(fullName);
      byId('v_dob').textContent = formatDate(emp.date_of_birth);
      byId('v_gender').textContent = valOrNA(emp.gender);
      byId('v_phone').textContent = valOrNA(emp.phone);
      byId('v_email').textContent = valOrNA(emp.email);
      byId('v_address').textContent = valOrNA(emp.address);

      byId('v_department').textContent = valOrNA(emp.department_name);
      byId('v_position').textContent = valOrNA(emp.position_title);
      byId('v_rank').textContent = emp.rank_label || formatRank(emp.rank);
      byId('v_hired').textContent = formatDate(emp.date_hired);
      byId('v_emp_type').textContent = valOrNA(emp.employment_type);
      byId('v_emp_status').textContent = valOrNA(emp.employment_status);

      const deactForm = byId('v_deactivate_form');
      const deactId = byId('v_deactivate_id');
      if (deactForm && deactId) {
        deactId.value = emp.id || '';
        deactForm.style.display = (emp.employment_status === 'Inactive') ? 'none' : 'inline';
      }
    } catch (err) {
      setMsg(byId('viewEmpError'), 'err', err.message);
    }
  };

  window.openEmployeeEditModal = async function (id) {
    activeEmpId = Number(id);
    setMsg(byId('editEmpMessage'), '', '');
    openModal('editEmployeeModal');

    try {
      const { employee: emp, departments, positions, managers } = await fetchEmployeeData(id);
      byId('e_id').value = emp.id || '';
      byId('e_employee_id').value = emp.employee_id || '';
      byId('e_first_name').value = emp.first_name || '';
      byId('e_middle_name').value = emp.middle_name || '';
      byId('e_last_name').value = emp.last_name || '';
      byId('e_suffix').value = emp.suffix || '';
      byId('e_date_of_birth').value = emp.date_of_birth || '';
      byId('e_gender').value = emp.gender || '';
      byId('e_phone').value = emp.phone || '';
      byId('e_email').value = emp.email || '';
      byId('e_address').value = emp.address || '';

      populateSelect(byId('e_department_id'), departments, emp.department_id, 'Select Department', d => d.name);
      populateSelect(byId('e_position_id'), positions, emp.position_id, 'Select Position', p => p.title);
      populateSelect(
        byId('e_manager_id'),
        (managers || []).filter(m => Number(m.id) !== Number(emp.id)),
        emp.manager_id,
        'No manager',
        m => `${m.employee_id} - ${m.first_name} ${m.last_name}`
      );

      byId('e_rank').value = emp.rank || 'employee';
      byId('e_date_hired').value = emp.date_hired || '';
      byId('e_employment_type').value = emp.employment_type || 'Full-time';
      byId('e_employment_status').value = emp.employment_status || 'Active';
    } catch (err) {
      setMsg(byId('editEmpMessage'), 'err', err.message);
    }
  };

  const switchEditBtn = byId('v_switch_edit_btn');
  if (switchEditBtn) {
    switchEditBtn.addEventListener('click', function () {
      const targetId = activeEmpId;
      closeModal('viewEmployeeModal');
      if (targetId) {
        window.openEmployeeEditModal(targetId);
      }
    });
  }

  const editForm = byId('editEmployeeForm');
  const saveBtn = byId('editEmployeeSaveBtn');
  if (editForm) {
    editForm.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (saveBtn) saveBtn.disabled = true;
      setMsg(byId('editEmpMessage'), '', '');

      const id = byId('e_id').value;
      try {
        const fd = new FormData(editForm);
        fd.append('ajax', '1');
        const res = await fetch(`/hr/edit_employee.php?id=${encodeURIComponent(id)}`, {
          method: 'POST',
          body: fd,
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await res.json();
        if (!res.ok || !data.ok) {
          throw new Error(data.error || 'Could not update employee.');
        }
        setMsg(byId('editEmpMessage'), 'ok', data.message || 'Employee updated successfully.');
        document.dispatchEvent(new CustomEvent('hris:employee-updated', { detail: { id: Number(id) } }));
        setTimeout(() => {
          closeModal('editEmployeeModal');
          window.openEmployeeViewModal(id);
        }, 450);
      } catch (err) {
        setMsg(byId('editEmpMessage'), 'err', err.message);
      } finally {
        if (saveBtn) saveBtn.disabled = false;
      }
    });
  }

  document.addEventListener('click', function (e) {
    const closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
      closeModal(closeBtn.getAttribute('data-close-modal'));
      return;
    }
    if (e.target.classList && e.target.classList.contains('emp-modal-backdrop')) {
      closeModal(e.target.id);
      return;
    }
    const viewLink = e.target.closest('[data-view-emp], a[href*="/hr/view_employee.php?id="]');
    if (viewLink) {
      e.preventDefault();
      const id = viewLink.getAttribute('data-view-emp')
        || new URL(viewLink.href, window.location.origin).searchParams.get('id');
      if (id) window.openEmployeeViewModal(id);
      return;
    }
    const editLink = e.target.closest('[data-edit-emp], a[href*="/hr/edit_employee.php?id="]');
    if (editLink) {
      e.preventDefault();
      const id = editLink.getAttribute('data-edit-emp')
        || new URL(editLink.href, window.location.origin).searchParams.get('id');
      if (id) window.openEmployeeEditModal(id);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.emp-modal-backdrop.is-open').forEach(m => closeModal(m.id));
    }
  });
})();
</script>