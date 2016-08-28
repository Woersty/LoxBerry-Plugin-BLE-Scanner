# LoxBerry BLE iBeaconScanner 
# Based on serveral other projects and adapted for the LoxBerry by Christian Woerstenfeld 
# Version 1.0
# 17.08.2016 10:51:15
# 
# Performs a simple device inquiry and returns a list of BLE discovered devices

import os
import sys
import struct
import re
import bluetooth._bluetooth as bluez

LE_META_EVENT = 0x3e
OGF_LE_CTL=0x08
OCF_LE_SET_SCAN_ENABLE=0x000C
EVT_LE_CONN_COMPLETE=0x01
EVT_LE_ADVERTISING_REPORT=0x02

def getBLESocket(devID):
	return bluez.hci_open_dev(devID)

def returnnumberpacket(pkt):
    myInteger = 0
    multiple = 256
    for i in range(len(pkt)):
        myInteger += struct.unpack("B",pkt[i:i+1])[0] * multiple
        multiple = 1
    return myInteger

def returnstringpacket(pkt):
    myString = "";
    for i in range(len(pkt)):
        myString += "%02x" %struct.unpack("B",pkt[i:i+1])[0]
    return myString

def get_packed_bdaddr(bdaddr_string):
    packable_addr = []
    addr = bdaddr_string.split(':')
    addr.reverse()
    for b in addr:
        packable_addr.append(int(b, 16))
    return struct.pack("<BBBBBB", *packable_addr)

def packed_bdaddr_to_string(bdaddr_packed):
    return ':'.join('%02x'%i for i in struct.unpack("<BBBBBB", bdaddr_packed[::-1]))

def hci_enable_le_scan(sock):
    hci_toggle_le_scan(sock, 0x01)

def hci_disable_le_scan(sock):
    hci_toggle_le_scan(sock, 0x00)

def hci_toggle_le_scan(sock, enable):
    cmd_pkt = struct.pack("<BB", enable, 0x00)
    bluez.hci_send_cmd(sock, OGF_LE_CTL, OCF_LE_SET_SCAN_ENABLE, cmd_pkt)

def hci_le_set_scan_parameters(sock):
    old_filter = sock.getsockopt( bluez.SOL_HCI, bluez.HCI_FILTER, 14)

def parse_events(sock, loop_count=4):
    old_filter = sock.getsockopt( bluez.SOL_HCI, bluez.HCI_FILTER, 14)
    flt = bluez.hci_filter_new()
    bluez.hci_filter_all_events(flt)
    bluez.hci_filter_set_ptype(flt, bluez.HCI_EVENT_PKT)
    sock.setsockopt( bluez.SOL_HCI, bluez.HCI_FILTER, flt )
    myFullList = []
    for i in range(0, loop_count):
        pkt = sock.recv(255)
        ptype, event, plen = struct.unpack("BBB", pkt[:3])
        if event == bluez.EVT_INQUIRY_RESULT_WITH_RSSI:
            i = 0
        elif event == bluez.EVT_NUM_COMP_PKTS:
            i = 0
        elif event == bluez.EVT_DISCONN_COMPLETE:
            i = 0
        elif event == LE_META_EVENT:
            subevent, = struct.unpack("B", pkt[3:4])
            pkt = pkt[4:]
            if subevent == EVT_LE_CONN_COMPLETE:
                le_handle_connection_complete(pkt)
            elif subevent == EVT_LE_ADVERTISING_REPORT:
                num_reports = struct.unpack("B", pkt[0:1])[0]
                report_pkt_offset = 0
                for i in range(0, num_reports):
                    # build the return string
                    Adstring = packed_bdaddr_to_string(pkt[report_pkt_offset + 3:report_pkt_offset + 9])
                    Adstring += ',' + returnstringpacket(pkt[report_pkt_offset -22: report_pkt_offset - 6])
                    Adstring += ',' + returnstringpacket(pkt[report_pkt_offset -6: report_pkt_offset - 4])
                    Adstring += ',' + returnstringpacket(pkt[report_pkt_offset -4: report_pkt_offset - 2])
                    try:
                        Adstring += ',' + returnstringpacket(pkt[report_pkt_offset -2:report_pkt_offset -1])
                    except: 1
                    #Prevent duplicates in results
                    if Adstring not in myFullList: myFullList.append(Adstring)
    sock.setsockopt( bluez.SOL_HCI, bluez.HCI_FILTER, old_filter )
    return myFullList

if __name__ == '__main__':
    dev_id = 0
    try:
        sock = bluez.hci_open_dev(dev_id)
    except:
        print("Error accessing bluetooth device")
        sys.exit(1)

    hci_le_set_scan_parameters(sock)
    hci_enable_le_scan(sock)

    mac_addr_list = []
    returnedList = parse_events(sock, 10)
    for beacon in returnedList:
            mac_addr = beacon[0:17]
            if re.match("[0-9a-f]{2}([-:])[0-9a-f]{2}(\\1[0-9a-f]{2}){4}$",mac_addr.lower()):
            	mac_addr_list.append(mac_addr)
    mac_addr_list_distinct = list(set(mac_addr_list))
    for beacon in mac_addr_list_distinct:
        print(beacon)
    sys.exit(0)
