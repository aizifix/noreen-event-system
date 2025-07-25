"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import axios from "axios";
import { secureStorage } from "@/app/utils/encryption";
import { protectRoute } from "@/app/utils/routeProtection";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { toast } from "@/components/ui/use-toast";
import {
  User,
  Lock,
  Palette,
  MessageSquare,
  Bell,
  Shield,
  Upload,
  Save,
  Eye,
  EyeOff,
  Trash2,
  Star,
  Calendar,
  Mail,
  Phone,
  MapPin,
  Camera,
  Loader2,
} from "lucide-react";
import ProfilePictureModal from "@/components/ProfilePictureModal";
import { userDataHelper } from "@/app/utils/userDataHelper";

interface UserProfile {
  user_id: number;
  user_firstName: string | null;
  user_lastName: string | null;
  user_email: string | null;
  user_contact: string | null;
  user_pfp: string | null;
  created_at: string;
}

interface WebsiteSettings {
  company_name: string;
  company_logo: string;
  hero_image: string;
  primary_color: string;
  secondary_color: string;
  contact_email: string;
  contact_phone: string;
  address: string;
  about_text: string;
  social_facebook: string;
  social_instagram: string;
  social_twitter: string;
}

interface Feedback {
  feedback_id: number;
  user_firstName: string;
  user_lastName: string;
  user_email: string;
  feedback_rating: number;
  feedback_text: string;
  created_at: string;
  venue_title?: string;
  store_name?: string;
}

export default function SettingsPage() {
  const router = useRouter();
  const [activeTab, setActiveTab] = useState("profile");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showConfirmPassword, setShowConfirmPassword] = useState(false);
  const [showProfilePictureModal, setShowProfilePictureModal] = useState(false);

  // Profile state
  const [userProfile, setUserProfile] = useState<UserProfile | null>(null);
  const [profileForm, setProfileForm] = useState({
    firstName: "",
    lastName: "",
    email: "",
    contact: "",
  });

  // Password state
  const [passwordForm, setPasswordForm] = useState({
    currentPassword: "",
    newPassword: "",
    confirmPassword: "",
  });

  // Website customization state
  const [websiteSettings, setWebsiteSettings] = useState<WebsiteSettings>({
    company_name: "Event Coordination System",
    company_logo: "",
    hero_image: "",
    primary_color: "#16a34a",
    secondary_color: "#059669",
    contact_email: "",
    contact_phone: "",
    address: "",
    about_text: "",
    social_facebook: "",
    social_instagram: "",
    social_twitter: "",
  });

  // Feedback state
  const [feedbacks, setFeedbacks] = useState<Feedback[]>([]);
  const [feedbackFilter, setFeedbackFilter] = useState("all");

  // Notification settings
  const [notificationSettings, setNotificationSettings] = useState({
    emailNotifications: true,
    smsNotifications: false,
    newBookings: true,
    paymentReminders: true,
    eventReminders: true,
    systemUpdates: false,
  });

  // Security settings
  const [securitySettings, setSecuritySettings] = useState({
    twoFactorAuth: false,
    loginAlerts: true,
    sessionTimeout: 30,
    passwordExpiry: 90,
  });

  useEffect(() => {
    try {
      protectRoute();
      const userData = secureStorage.getItem("user");
      if (
        !userData ||
        !userData.user_role ||
        userData.user_role.toLowerCase() !== "admin"
      ) {
        router.push("/auth/login");
        return;
      }
      fetchUserProfile();
      fetchWebsiteSettings();
      fetchFeedbacks();
    } catch (error) {
      console.error("Error accessing user data:", error);
      router.push("/auth/login");
    }
  }, [router]);

  const fetchUserProfile = async () => {
    try {
      setIsLoading(true);
      const userData = secureStorage.getItem("user");
      const response = await axios.get(
        "http://localhost/events-api/admin.php",
        {
          params: {
            operation: "getUserProfile",
            user_id: userData?.user_id,
          },
        }
      );

      if (response.data.status === "success") {
        const profile = response.data.profile;
        setUserProfile(profile);
        setProfileForm({
          firstName: profile.user_firstName || "",
          lastName: profile.user_lastName || "",
          email: profile.user_email || "",
          contact: profile.user_contact || "",
        });
      }
    } catch (error) {
      console.error("Error fetching profile:", error);
      toast({
        title: "Error",
        description: "Failed to load profile data",
        variant: "destructive",
      });
    } finally {
      setIsLoading(false);
    }
  };

  const fetchWebsiteSettings = async () => {
    try {
      const response = await axios.get(
        "http://localhost/events-api/admin.php",
        {
          params: { operation: "getWebsiteSettings" },
        }
      );

      if (response.data.status === "success") {
        const settings = response.data.settings;
        setWebsiteSettings({
          company_name: settings.company_name || "Event Coordination System",
          company_logo: settings.company_logo || "",
          hero_image: settings.hero_image || "",
          primary_color: settings.primary_color || "#16a34a",
          secondary_color: settings.secondary_color || "#059669",
          contact_email: settings.contact_email || "",
          contact_phone: settings.contact_phone || "",
          address: settings.address || "",
          about_text: settings.about_text || "",
          social_facebook: settings.social_facebook || "",
          social_instagram: settings.social_instagram || "",
          social_twitter: settings.social_twitter || "",
        });
      }
    } catch (error) {
      console.error("Error fetching website settings:", error);
    }
  };

  const fetchFeedbacks = async () => {
    try {
      const response = await axios.get(
        "http://localhost/events-api/admin.php",
        {
          params: { operation: "getAllFeedbacks" },
        }
      );

      if (response.data.status === "success") {
        setFeedbacks(response.data.feedbacks);
      }
    } catch (error) {
      console.error("Error fetching feedbacks:", error);
    }
  };

  const handleProfileUpdate = async () => {
    try {
      setIsSaving(true);
      const userData = secureStorage.getItem("user");
      const response = await axios.post(
        "http://localhost/events-api/admin.php",
        {
          operation: "updateUserProfile",
          user_id: userData?.user_id,
          ...profileForm,
        }
      );

      if (response.data.status === "success") {
        toast({
          title: "Success",
          description: "Profile updated successfully",
        });
        await fetchUserProfile();
      } else {
        throw new Error(response.data.message);
      }
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to update profile",
        variant: "destructive",
      });
    } finally {
      setIsSaving(false);
    }
  };

  const handlePasswordChange = async () => {
    if (passwordForm.newPassword !== passwordForm.confirmPassword) {
      toast({
        title: "Error",
        description: "New passwords do not match",
        variant: "destructive",
      });
      return;
    }

    try {
      setIsSaving(true);
      const userData = secureStorage.getItem("user");
      const response = await axios.post(
        "http://localhost/events-api/admin.php",
        {
          operation: "changePassword",
          user_id: userData?.user_id,
          currentPassword: passwordForm.currentPassword,
          newPassword: passwordForm.newPassword,
        }
      );

      if (response.data.status === "success") {
        toast({
          title: "Success",
          description: "Password changed successfully",
        });
        setPasswordForm({
          currentPassword: "",
          newPassword: "",
          confirmPassword: "",
        });
      } else {
        throw new Error(response.data.message);
      }
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to change password",
        variant: "destructive",
      });
    } finally {
      setIsSaving(false);
    }
  };

  const handleWebsiteSettingsUpdate = async () => {
    try {
      setIsSaving(true);
      const response = await axios.post(
        "http://localhost/events-api/admin.php",
        {
          operation: "updateWebsiteSettings",
          settings: websiteSettings,
        }
      );

      if (response.data.status === "success") {
        toast({
          title: "Success",
          description: "Website settings updated successfully",
        });
      } else {
        throw new Error(response.data.message);
      }
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to update website settings",
        variant: "destructive",
      });
    } finally {
      setIsSaving(false);
    }
  };

  const handleFileUpload = async (file: File, type: string) => {
    const formData = new FormData();
    formData.append("file", file);
    formData.append("operation", "uploadFile");
    formData.append("fileType", type);

    try {
      const response = await axios.post(
        "http://localhost/events-api/admin.php",
        formData,
        {
          headers: { "Content-Type": "multipart/form-data" },
        }
      );

      if (response.data.status === "success") {
        return response.data.filePath;
      } else {
        throw new Error(response.data.message);
      }
    } catch (error: any) {
      toast({
        title: "Error",
        description: error.message || "Failed to upload file",
        variant: "destructive",
      });
      return null;
    }
  };

  const handleImageUpload = async (
    event: React.ChangeEvent<HTMLInputElement>,
    imageType: string
  ) => {
    const file = event.target.files?.[0];
    if (!file) return;

    const filePath = await handleFileUpload(file, imageType);
    if (filePath) {
      if (imageType === "profile") {
        // Update profile picture
        setUserProfile((prev) =>
          prev ? { ...prev, user_pfp: filePath } : null
        );
      } else {
        // Update website images
        setWebsiteSettings((prev) => ({
          ...prev,
          [imageType]: filePath,
        }));
      }
    }
  };

  const deleteFeedback = async (feedbackId: number) => {
    try {
      const response = await axios.post(
        "http://localhost/events-api/admin.php",
        {
          operation: "deleteFeedback",
          feedback_id: feedbackId,
        }
      );

      if (response.data.status === "success") {
        setFeedbacks(feedbacks.filter((f) => f.feedback_id !== feedbackId));
        toast({
          title: "Success",
          description: "Feedback deleted successfully",
        });
      }
    } catch (error) {
      toast({
        title: "Error",
        description: "Failed to delete feedback",
        variant: "destructive",
      });
    }
  };

  const renderStars = (rating: number) => {
    return Array.from({ length: 5 }, (_, i) => (
      <Star
        key={i}
        className={`h-4 w-4 ${
          i < rating ? "text-yellow-400 fill-current" : "text-gray-300"
        }`}
      />
    ));
  };

  const filteredFeedbacks = feedbacks.filter((feedback) => {
    if (feedbackFilter === "all") return true;
    if (feedbackFilter === "high") return feedback.feedback_rating >= 4;
    if (feedbackFilter === "low") return feedback.feedback_rating <= 2;
    return true;
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <Loader2 className="h-8 w-8 animate-spin" />
      </div>
    );
  }

  const handleProfilePictureUpload = (filePath: string) => {
    setUserProfile((prev) => (prev ? { ...prev, user_pfp: filePath } : null));

    // Use a safer approach: first refresh the profile data, then update secure storage
    setTimeout(async () => {
      await fetchUserProfile(); // Refresh the profile data first
      await updateUserDataSafely(); // Then safely update the secure storage for navbar
    }, 100);
  };

  // Function to safely update user data in secure storage after profile picture upload
  const updateUserDataSafely = async () => {
    try {
      const token = secureStorage.getItem("authToken");
      const userId = secureStorage.getItem("userId");

      if (!token || !userId) return;

      const response = await axios.get(
        `http://localhost/events-api/admin.php?operation=getUserProfile&user_id=${userId}`,
        {
          headers: { Authorization: `Bearer ${token}` },
        }
      );

      if (response.data.status === "success") {
        const profile = response.data.profile;

        // Get current user data from secure storage
        const currentUser = secureStorage.getItem("user");
        if (currentUser && typeof currentUser === "object") {
          // Update only the profile picture field while preserving all other data
          const updatedUser = {
            ...currentUser,
            user_pfp: profile.user_pfp,
          };

          console.log(
            "Safely updating user data with new profile picture:",
            updatedUser
          );
          secureStorage.setItem("user", updatedUser);

          // Dispatch event to update navbar
          window.dispatchEvent(new CustomEvent("userDataChanged"));
        }
      }
    } catch (error) {
      console.error("Error updating user data safely:", error);
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <h1 className="text-3xl font-bold">Settings</h1>
        <div className="text-sm text-gray-500">
          Manage your account and system preferences
        </div>
      </div>

      <Tabs defaultValue="profile" className="w-full">
        <TabsList className="grid w-full grid-cols-6">
          <TabsTrigger value="profile" className="flex items-center gap-2">
            <User className="h-4 w-4" />
            Profile
          </TabsTrigger>
          <TabsTrigger value="security" className="flex items-center gap-2">
            <Lock className="h-4 w-4" />
            Security
          </TabsTrigger>
          <TabsTrigger value="website" className="flex items-center gap-2">
            <Palette className="h-4 w-4" />
            Website
          </TabsTrigger>
          <TabsTrigger value="feedback" className="flex items-center gap-2">
            <MessageSquare className="h-4 w-4" />
            Feedback
          </TabsTrigger>
          <TabsTrigger
            value="notifications"
            className="flex items-center gap-2"
          >
            <Bell className="h-4 w-4" />
            Notifications
          </TabsTrigger>
          <TabsTrigger value="advanced" className="flex items-center gap-2">
            <Shield className="h-4 w-4" />
            Advanced
          </TabsTrigger>
        </TabsList>

        {/* Profile Tab */}
        <TabsContent value="profile" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <User className="h-5 w-5" />
                Profile Information
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Profile Picture */}
              <div className="flex items-center space-x-6">
                <div className="relative">
                  <img
                    src={
                      userProfile?.user_pfp &&
                      userProfile.user_pfp.trim() !== ""
                        ? `http://localhost/events-api/serve-image.php?path=${encodeURIComponent(userProfile.user_pfp)}`
                        : "/default_pfp.png"
                    }
                    alt="Profile"
                    className="h-24 w-24 rounded-full object-cover border-4 border-gray-200"
                  />
                  <button
                    type="button"
                    onClick={() => setShowProfilePictureModal(true)}
                    className="absolute bottom-0 right-0 bg-green-600 text-white p-2 rounded-full cursor-pointer hover:bg-green-700 transition-colors"
                  >
                    <Camera className="h-4 w-4" />
                  </button>
                </div>
                <div>
                  <h3 className="text-lg font-medium">
                    {userProfile?.user_firstName || ""}{" "}
                    {userProfile?.user_lastName || ""}
                  </h3>
                  <p className="text-gray-500">Administrator</p>
                  <p className="text-sm text-gray-400">
                    Member since{" "}
                    {userProfile?.created_at
                      ? new Date(userProfile.created_at).toLocaleDateString()
                      : "Unknown"}
                  </p>
                </div>
              </div>

              {/* Profile Form */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <Label htmlFor="firstName">First Name</Label>
                  <Input
                    id="firstName"
                    value={profileForm.firstName}
                    onChange={(e) =>
                      setProfileForm({
                        ...profileForm,
                        firstName: e.target.value,
                      })
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="lastName">Last Name</Label>
                  <Input
                    id="lastName"
                    value={profileForm.lastName}
                    onChange={(e) =>
                      setProfileForm({
                        ...profileForm,
                        lastName: e.target.value,
                      })
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="email">Email Address</Label>
                  <div className="relative">
                    <Mail className="absolute left-3 top-3 h-4 w-4 text-gray-400" />
                    <Input
                      id="email"
                      type="email"
                      className="pl-10"
                      value={profileForm.email}
                      onChange={(e) =>
                        setProfileForm({
                          ...profileForm,
                          email: e.target.value,
                        })
                      }
                    />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="contact">Contact Number</Label>
                  <div className="relative">
                    <Phone className="absolute left-3 top-3 h-4 w-4 text-gray-400" />
                    <Input
                      id="contact"
                      className="pl-10"
                      value={profileForm.contact}
                      onChange={(e) =>
                        setProfileForm({
                          ...profileForm,
                          contact: e.target.value,
                        })
                      }
                    />
                  </div>
                </div>
              </div>

              <Button
                onClick={handleProfileUpdate}
                disabled={isSaving}
                className="w-full md:w-auto"
              >
                {isSaving ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Save className="h-4 w-4 mr-2" />
                )}
                Save Changes
              </Button>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Security Tab */}
        <TabsContent value="security" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Lock className="h-5 w-5" />
                Change Password
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="currentPassword">Current Password</Label>
                  <div className="relative">
                    <Input
                      id="currentPassword"
                      type={showPassword ? "text" : "password"}
                      value={passwordForm.currentPassword}
                      onChange={(e) =>
                        setPasswordForm({
                          ...passwordForm,
                          currentPassword: e.target.value,
                        })
                      }
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword(!showPassword)}
                      className="absolute right-3 top-3 text-gray-400 hover:text-gray-600"
                    >
                      {showPassword ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="newPassword">New Password</Label>
                  <div className="relative">
                    <Input
                      id="newPassword"
                      type={showNewPassword ? "text" : "password"}
                      value={passwordForm.newPassword}
                      onChange={(e) =>
                        setPasswordForm({
                          ...passwordForm,
                          newPassword: e.target.value,
                        })
                      }
                    />
                    <button
                      type="button"
                      onClick={() => setShowNewPassword(!showNewPassword)}
                      className="absolute right-3 top-3 text-gray-400 hover:text-gray-600"
                    >
                      {showNewPassword ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="confirmPassword">Confirm New Password</Label>
                  <div className="relative">
                    <Input
                      id="confirmPassword"
                      type={showConfirmPassword ? "text" : "password"}
                      value={passwordForm.confirmPassword}
                      onChange={(e) =>
                        setPasswordForm({
                          ...passwordForm,
                          confirmPassword: e.target.value,
                        })
                      }
                    />
                    <button
                      type="button"
                      onClick={() =>
                        setShowConfirmPassword(!showConfirmPassword)
                      }
                      className="absolute right-3 top-3 text-gray-400 hover:text-gray-600"
                    >
                      {showConfirmPassword ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  </div>
                </div>
              </div>
              <Button
                onClick={handlePasswordChange}
                disabled={isSaving}
                className="w-full md:w-auto"
              >
                {isSaving ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Lock className="h-4 w-4 mr-2" />
                )}
                Change Password
              </Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Security Settings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <Label className="text-base">Two-Factor Authentication</Label>
                  <p className="text-sm text-gray-500">
                    Add an extra layer of security to your account
                  </p>
                </div>
                <Switch
                  checked={securitySettings.twoFactorAuth}
                  onCheckedChange={(checked: boolean) =>
                    setSecuritySettings({
                      ...securitySettings,
                      twoFactorAuth: checked,
                    })
                  }
                />
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <Label className="text-base">Login Alerts</Label>
                  <p className="text-sm text-gray-500">
                    Get notified when someone logs into your account
                  </p>
                </div>
                <Switch
                  checked={securitySettings.loginAlerts}
                  onCheckedChange={(checked: boolean) =>
                    setSecuritySettings({
                      ...securitySettings,
                      loginAlerts: checked,
                    })
                  }
                />
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Website Customization Tab */}
        <TabsContent value="website" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Palette className="h-5 w-5" />
                Website Appearance
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <Label htmlFor="companyName">Company Name</Label>
                  <Input
                    id="companyName"
                    value={websiteSettings.company_name}
                    onChange={(e) =>
                      setWebsiteSettings({
                        ...websiteSettings,
                        company_name: e.target.value,
                      })
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="contactEmail">Contact Email</Label>
                  <Input
                    id="contactEmail"
                    type="email"
                    value={websiteSettings.contact_email}
                    onChange={(e) =>
                      setWebsiteSettings({
                        ...websiteSettings,
                        contact_email: e.target.value,
                      })
                    }
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="primaryColor">Primary Color</Label>
                  <div className="flex gap-2">
                    <Input
                      id="primaryColor"
                      type="color"
                      value={websiteSettings.primary_color}
                      onChange={(e) =>
                        setWebsiteSettings({
                          ...websiteSettings,
                          primary_color: e.target.value,
                        })
                      }
                      className="w-16 h-10"
                    />
                    <Input
                      value={websiteSettings.primary_color}
                      onChange={(e) =>
                        setWebsiteSettings({
                          ...websiteSettings,
                          primary_color: e.target.value,
                        })
                      }
                      className="flex-1"
                    />
                  </div>
                </div>
                <div className="space-y-2">
                  <Label htmlFor="secondaryColor">Secondary Color</Label>
                  <div className="flex gap-2">
                    <Input
                      id="secondaryColor"
                      type="color"
                      value={websiteSettings.secondary_color}
                      onChange={(e) =>
                        setWebsiteSettings({
                          ...websiteSettings,
                          secondary_color: e.target.value,
                        })
                      }
                      className="w-16 h-10"
                    />
                    <Input
                      value={websiteSettings.secondary_color}
                      onChange={(e) =>
                        setWebsiteSettings({
                          ...websiteSettings,
                          secondary_color: e.target.value,
                        })
                      }
                      className="flex-1"
                    />
                  </div>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="aboutText">About Us Text</Label>
                <Textarea
                  id="aboutText"
                  rows={4}
                  value={websiteSettings.about_text}
                  onChange={(e) =>
                    setWebsiteSettings({
                      ...websiteSettings,
                      about_text: e.target.value,
                    })
                  }
                  placeholder="Tell visitors about your company..."
                />
              </div>

              <div className="space-y-4">
                <h3 className="text-lg font-medium">Images</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div className="space-y-2">
                    <Label>Company Logo</Label>
                    <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                      {websiteSettings.company_logo ? (
                        <img
                          src={`http://localhost/events-api/${websiteSettings.company_logo}`}
                          alt="Company Logo"
                          className="h-16 mx-auto mb-2"
                        />
                      ) : (
                        <Upload className="h-12 w-12 mx-auto text-gray-400 mb-2" />
                      )}
                      <label className="cursor-pointer text-sm text-green-600 hover:text-green-700">
                        Upload Logo
                        <input
                          type="file"
                          accept="image/*"
                          className="hidden"
                          onChange={(e) => handleImageUpload(e, "company_logo")}
                        />
                      </label>
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label>Hero Image</Label>
                    <div className="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center">
                      {websiteSettings.hero_image ? (
                        <img
                          src={`http://localhost/events-api/${websiteSettings.hero_image}`}
                          alt="Hero Image"
                          className="h-16 mx-auto mb-2 rounded"
                        />
                      ) : (
                        <Upload className="h-12 w-12 mx-auto text-gray-400 mb-2" />
                      )}
                      <label className="cursor-pointer text-sm text-green-600 hover:text-green-700">
                        Upload Hero Image
                        <input
                          type="file"
                          accept="image/*"
                          className="hidden"
                          onChange={(e) => handleImageUpload(e, "hero_image")}
                        />
                      </label>
                    </div>
                  </div>
                </div>
              </div>

              <Button
                onClick={handleWebsiteSettingsUpdate}
                disabled={isSaving}
                className="w-full md:w-auto"
              >
                {isSaving ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Save className="h-4 w-4 mr-2" />
                )}
                Save Website Settings
              </Button>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Feedback Management Tab */}
        <TabsContent value="feedback" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <MessageSquare className="h-5 w-5" />
                Customer Feedback
              </CardTitle>
              <div className="flex gap-2">
                <Button
                  variant={feedbackFilter === "all" ? "default" : "outline"}
                  size="sm"
                  onClick={() => setFeedbackFilter("all")}
                >
                  All
                </Button>
                <Button
                  variant={feedbackFilter === "high" ? "default" : "outline"}
                  size="sm"
                  onClick={() => setFeedbackFilter("high")}
                >
                  High Rated (4-5★)
                </Button>
                <Button
                  variant={feedbackFilter === "low" ? "default" : "outline"}
                  size="sm"
                  onClick={() => setFeedbackFilter("low")}
                >
                  Low Rated (1-2★)
                </Button>
              </div>
            </CardHeader>
            <CardContent>
              <div className="space-y-4">
                {filteredFeedbacks.map((feedback) => (
                  <div
                    key={feedback.feedback_id}
                    className="border rounded-lg p-4 space-y-3"
                  >
                    <div className="flex items-start justify-between">
                      <div>
                        <div className="flex items-center gap-2">
                          <h4 className="font-medium">
                            {feedback.user_firstName} {feedback.user_lastName}
                          </h4>
                          <div className="flex">
                            {renderStars(feedback.feedback_rating)}
                          </div>
                        </div>
                        <p className="text-sm text-gray-500">
                          {feedback.user_email}
                        </p>
                        {(feedback.venue_title || feedback.store_name) && (
                          <p className="text-sm text-gray-500">
                            For: {feedback.venue_title || feedback.store_name}
                          </p>
                        )}
                      </div>
                      <div className="flex items-center gap-2">
                        <span className="text-sm text-gray-400">
                          {new Date(feedback.created_at).toLocaleDateString()}
                        </span>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => deleteFeedback(feedback.feedback_id)}
                          className="text-red-500 hover:text-red-700"
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                    {feedback.feedback_text && (
                      <p className="text-gray-700">{feedback.feedback_text}</p>
                    )}
                  </div>
                ))}
                {filteredFeedbacks.length === 0 && (
                  <div className="text-center py-8 text-gray-500">
                    No feedback found for the selected filter.
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Notifications Tab */}
        <TabsContent value="notifications" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Bell className="h-5 w-5" />
                Notification Preferences
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-base">Email Notifications</Label>
                    <p className="text-sm text-gray-500">
                      Receive notifications via email
                    </p>
                  </div>
                  <Switch
                    checked={notificationSettings.emailNotifications}
                    onCheckedChange={(checked: boolean) =>
                      setNotificationSettings({
                        ...notificationSettings,
                        emailNotifications: checked,
                      })
                    }
                  />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-base">New Bookings</Label>
                    <p className="text-sm text-gray-500">
                      Get notified when new bookings are made
                    </p>
                  </div>
                  <Switch
                    checked={notificationSettings.newBookings}
                    onCheckedChange={(checked: boolean) =>
                      setNotificationSettings({
                        ...notificationSettings,
                        newBookings: checked,
                      })
                    }
                  />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-base">Payment Reminders</Label>
                    <p className="text-sm text-gray-500">
                      Remind clients about upcoming payments
                    </p>
                  </div>
                  <Switch
                    checked={notificationSettings.paymentReminders}
                    onCheckedChange={(checked: boolean) =>
                      setNotificationSettings({
                        ...notificationSettings,
                        paymentReminders: checked,
                      })
                    }
                  />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-base">Event Reminders</Label>
                    <p className="text-sm text-gray-500">
                      Get reminded about upcoming events
                    </p>
                  </div>
                  <Switch
                    checked={notificationSettings.eventReminders}
                    onCheckedChange={(checked: boolean) =>
                      setNotificationSettings({
                        ...notificationSettings,
                        eventReminders: checked,
                      })
                    }
                  />
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* Advanced Tab */}
        <TabsContent value="advanced" className="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Shield className="h-5 w-5" />
                Advanced Settings
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              <div className="space-y-4">
                <div>
                  <Label className="text-base">Session Timeout (minutes)</Label>
                  <p className="text-sm text-gray-500 mb-2">
                    Automatically log out after inactivity
                  </p>
                  <Input
                    type="number"
                    value={securitySettings.sessionTimeout}
                    onChange={(e) =>
                      setSecuritySettings({
                        ...securitySettings,
                        sessionTimeout: parseInt(e.target.value) || 30,
                      })
                    }
                    className="w-32"
                  />
                </div>
                <div>
                  <Label className="text-base">Password Expiry (days)</Label>
                  <p className="text-sm text-gray-500 mb-2">
                    Require password change after this period
                  </p>
                  <Input
                    type="number"
                    value={securitySettings.passwordExpiry}
                    onChange={(e) =>
                      setSecuritySettings({
                        ...securitySettings,
                        passwordExpiry: parseInt(e.target.value) || 90,
                      })
                    }
                    className="w-32"
                  />
                </div>
              </div>

              <div className="border-t pt-6">
                <h3 className="text-lg font-medium text-red-600 mb-4">
                  Danger Zone
                </h3>
                <div className="space-y-4">
                  <div className="border border-red-200 rounded-lg p-4">
                    <h4 className="font-medium">Clear All Data</h4>
                    <p className="text-sm text-gray-500 mb-3">
                      This will permanently delete all events, bookings, and
                      related data. This action cannot be undone.
                    </p>
                    <Button variant="destructive" size="sm">
                      Clear All Data
                    </Button>
                  </div>
                  <div className="border border-red-200 rounded-lg p-4">
                    <h4 className="font-medium">Export Data</h4>
                    <p className="text-sm text-gray-500 mb-3">
                      Download a backup of all your data before making major
                      changes.
                    </p>
                    <Button variant="outline" size="sm">
                      Export Data
                    </Button>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Profile Picture Modal */}
      <ProfilePictureModal
        isOpen={showProfilePictureModal}
        onClose={() => setShowProfilePictureModal(false)}
        onUploadSuccess={handleProfilePictureUpload}
        uploadEndpoint="http://localhost/events-api/admin.php"
        userId={userProfile?.user_id || 0}
      />
    </div>
  );
}
