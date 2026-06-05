<?php

namespace App\Assistant\Enums;

enum FinancialIntent: string
{
    case CREATE_EXPENSE = 'create_expense';
    case CREATE_INCOME = 'create_income';
    case QUERY_BALANCE = 'query_balance';
    case QUERY_CATEGORY_SPENDING = 'query_category_spending';
    case QUERY_MONTH_REPORT = 'query_month_report';
    case UPDATE_TRANSACTION = 'update_transaction';
    case DELETE_TRANSACTION = 'delete_transaction';
    case CREATE_BUDGET = 'create_budget';
    case QUERY_BUDGETS = 'query_budgets';
    case CREATE_GOAL = 'create_goal';
    case CREATE_NOTE = 'create_note';
    case QUERY_NOTES = 'query_notes';
    case CREATE_REMINDER = 'create_reminder';
    case QUERY_REMINDERS = 'query_reminders';
    case CREATE_DRIVE_FILE = 'create_drive_file';
    case QUERY_DRIVE_FILES = 'query_drive_files';
    case ATTACH_RECEIPT = 'attach_receipt';
    case LIST_TRANSACTIONS = 'list_transactions';
    case HELP = 'help';
    case UNKNOWN = 'unknown';
}
