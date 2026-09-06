package ru.kapouch.evotor;

final class OrderRecord {
    String orderId = "";
    String orderNumber = "";
    String title = "";
    String description = "";
    String status = "new";
    String actionUrl = "";
    String actionToken = "";
    String lastError = "";
    long receivedAt = 0L;
    int reminderCount = 0;
}
