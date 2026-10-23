-- دەسەڵاتەکانی دکتۆر لەسەر کەیس و وەسڵ (admin/settings.php?tab=access, and
-- admin/role-permissions.php). Both OFF by default — today's behaviour.
--
--   cases.delete_own         a doctor may delete his OWN case — only while no
--                            money on it has been received and approved
--                            (CaseDeletion::delete refuses otherwise).
--   cases.edit_sent_invoice  a doctor may correct the work (treatments, prices)
--                            on his invoice after sending it, and its discount
--                            while it is still with the accountant. Money that
--                            was received never changes: the received figure,
--                            دراوی پێشوو and ئامانەت stay locked, and the total
--                            can never drop below what was paid.
INSERT IGNORE INTO permission_definitions (permission_key, permission_name, permission_name_ku, category, description) VALUES
('cases.delete_own', 'Doctor deletes own case', 'دکتۆر کەیسی خۆی بسڕێتەوە', 'cases',
 'A doctor may delete his own case while no money on it has been received and approved.'),
('cases.edit_sent_invoice', 'Doctor edits sent/approved invoice work', 'دکتۆر دەستکاری وەسڵی نێردراو بکات', 'cases',
 'A doctor may correct treatments and prices on his invoice after sending it. Received money is never editable by a doctor.');

INSERT IGNORE INTO role_permissions (role, permission_key, is_allowed) VALUES
('doctor', 'cases.delete_own', 0), ('doctor', 'cases.edit_sent_invoice', 0);
